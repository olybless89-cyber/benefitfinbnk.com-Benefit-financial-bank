<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Settings;
use App\Models\Plans;
use App\Models\Agent;
use App\Models\User_plans;
use App\Models\Admin;
use App\Models\Faq;
use App\Models\Images;
use App\Models\Testimony;
use App\Models\Content;
use App\Models\Asset;
use App\Models\Mt4Details;
use App\Models\Deposit;
use App\Models\Wdmethod;
use App\Models\Withdrawal;
use App\Models\Cp_transaction;
use App\Models\Tp_Transaction;
use App\Models\Activity;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use App\Http\Traits\CPTrait;

use App\Mail\NewNotification;
use App\Models\Kyc;
use App\Traits\PingServer;
use Illuminate\Support\Facades\Mail;
use App\Helpers\NotificationHelper;

class ManageUsersController extends Controller
{
    use PingServer;

    // See user wallet balances
    public function loginactivity($id)
    {

        $user = User::where('id', $id)->first();

        return view('admin.Users.loginactivity', [
            'activities' => Activity::where('user', $id)->orderByDesc('id')->get(),
            'title' => "$user->name login activities",
            'user' => $user,
        ]);
    }

    public function showUsers($id)
    {
        $user = User::where('id', $id)->first();
        $ref = User::whereNull('ref_by')->where('id', '!=', $id)->get();

        return view('admin.Users.referral', [
            'title' => "Add users to $user->name referral list",
            'user' => $user,
            'ref' => $ref,
        ]);
    }

    public function fetchUsers()
    {
        $users = User::orderByDesc('id')->get();
        return response()->json([
            'message' => 'Success',
            'data' => $users,
            'code' => 200
        ]);
    }


    public function addReferral(Request $request)
    {
        $user = User::where('id', $request->user_id)->first();
        $ref = User::where('id', $request->ref_id)->first();

        $ref->ref_by = $user->id;
        $ref->save();
        return redirect()->back()
            ->with('success', "$ref->name is now referred by $user->name successfully");
    }

    public function clearactivity($id)
    {
        $activities = Activity::where('user', $id)->get();

        if (count($activities) > 0) {
            foreach ($activities as $act) {
                Activity::where('id', $act->id)->delete();
            }
            return redirect()->back()
                ->with('success', 'Activity Cleared Successfully!');
        }
        return redirect()->back()
            ->with('message', 'No Activity to clear!');
    }

    public function markplanas($status, $id)
    {
        // Get the loan plan
        $plan = User_plans::where('id', $id)->first();
        
        // Update the loan status
        User_plans::where('id', $id)->update([
            'active' => $status,
        ]);
        
        // Make sure we have a valid plan and user
        if ($plan && $plan->user) {
            // Get the user who owns this loan
            $user = User::find($plan->user);
            
            if ($user) {
                // Create different notification messages based on status
                $title = "Loan Status Updated";
                $message = "Your loan application status has been updated to $status.";
                $icon = "clipboard-list";
                $type = "info";
                
                // Credit user account if loan is approved or activated
                if ($status == "Processed") {
                    // Only credit the account if it hasn't been credited before
                    if ($plan->active != "Processed") {
                        // Update user account balance
                        $user->account_bal += $plan->amount;
                        $user->save();
                        
                        // Create transaction record
                        Tp_Transaction::create([
                            'user' => $user->id,
                            'plan' => "Loan",
                            'amount' => $plan->amount,
                            'type' => "Loan Credit",
                        ]);
                    }
                }
                
                if ($status == "Processed") {
                    $title = "Loan Approved";
                    $message = "Congratulations! Your loan application for " . number_format($plan->amount, 2) . " has been approved and credited to your account. Your new balance is " . number_format($user->account_bal, 2) . ".";
                    $icon = "check-circle";
                    $type = "success";
                } elseif ($status == "Rejected") {
                    $title = "Loan Application Rejected";
                    $message = "We regret to inform you that your loan application has been rejected. Please contact support for more information.";
                    $icon = "x-circle";
                    $type = "danger";
                } elseif ($status == "Active") {
                    $title = "Loan Now Active";
                    $message = "Your loan of " . number_format($plan->amount, 2) . " is now active and has been credited to your account. Your new balance is " . number_format($user->account_bal, 2) . ".";
                    $icon = "credit-card";
                    $type = "success";
                } elseif ($status == "Completed") {
                    $title = "Loan Completed";
                    $message = "Your loan has been marked as completed. Thank you for your timely repayments.";
                    $icon = "check-circle";
                    $type = "success";
                }
                
                // Create notification for the user
                NotificationHelper::create(
                    $user,
                    $message,
                    $title,
                    $type,
                    $icon,
                    route('veiwloan')
                );
            }
        }
        
        return redirect()->back()
            ->with('success', "Loan state changed to $status");
    }

    public function viewuser($id)
    {
        $user = User::where('id', $id)->first();
        return view('admin.Users.userdetails', [
            'user' => $user,
            'pl' => Plans::orderByDesc('id')->get(),
            'title' => "Manage $user->name",
        ]);
    }
    //block user
    public function ublock($id)
    {
        User::where('id', $id)->update([
            'status' => 'blocked',
        ]);
        return redirect()->back()->with('success', 'Action Sucessful!');
    }

    public function dormant($id)
    {
    User::where('id', $id)->update([
        'account_status' => 'inactive',
    ]);

    return redirect()->back()->with('success', 'Action Successful! Account marked as dormant.');
    }

    public function undormant($id)
    {
    User::where('id', $id)->update([
        'account_status' => 'active',
    ]);

    return redirect()->back()->with('success', 'Action Successful! Account reactivated from dormant.');
    }

      //Return create users route
    
    //unblock user
    public function unblock($id)
    {
        User::where('id', $id)->update([
            'status' => 'active',
        ]);
        return redirect()->back()->with('success', 'Action Sucessful!');
    }

    //Turn on/off user trade
    public function usertrademode($id, $action)
    {
        if ($action == "on") {
            $action = "on";
        } elseif ($action == "off") {
            $action = "off";
        } else {
            return redirect() - back()->with('message', "Unknown action!");
        }

        User::where('id', $id)->update([
            'trade_mode' => $action,
        ]);
        return redirect()->back()->with('success', "User trade mode has been turned $action.");
    }

    //Manually Verify users email
    public function emailverify($id)
    {
        User::where('id', $id)->update([
            'email_verified_at' => \Carbon\Carbon::now(),
        ]);
        return redirect()->back()->with('success', 'User Email have been verified');
    }

    //Reset Password
    public function resetpswd($id)
    {
        User::where('id', $id)
            ->update([
                'password' => Hash::make('user01236'),
            ]);
        return redirect()->back()->with('success', 'Password has been reset to default');
    }

    //Clear user Account
    public function clearacct(Request $request, $id)
    {
        $settings = Settings::where('id', 1)->first();

        $deposits = Deposit::where('user', $id)->get();
        if (!empty($deposits)) {
            foreach ($deposits as $deposit) {
                Deposit::where('id', $deposit->id)->delete();
            }
        }

        $withdrawals = Withdrawal::where('user', $id)->get();
        if (!empty($withdrawals)) {
            foreach ($withdrawals as $withdrawals) {
                Withdrawal::where('id', $withdrawals->id)->delete();
            }
        }

        User::where('id', $id)->update([
            'account_bal' => '0',
            'roi' => '0',
            'bonus' => '0',
            'ref_bonus' => '0',
        ]);
        return redirect()->back()->with('success', "Account cleared to $settings->currency 0.00");
    }

    //Access users account
    public function switchuser($id)
    {
        $user = User::where('id', $id)->first();
        Auth::loginUsingId($user->id, true);
        return redirect()->route('dashboard')->with('success', "You are logged in as $user->name !");
    }

    //Manually Add Trading History to Users Route
    public function addHistory(Request $request)
    {
        Tp_Transaction::create([
            'user' => $request->user_id,
            'plan' => $request->plan,
            'amount' => $request->amount,
            'type' => $request->type,
        ]);
        $user = User::where('id', $request->user_id)->first();
        $user_bal = $user->account_bal;

        if (isset($request['amount']) > 0) {
            User::where('id', $request->user_id)
                ->update([
                    'account_bal' => $user_bal + $request->amount,
                ]);
        }
        $user_roi = $user->roi;
        if (isset($request['type']) == "ROI") {
            User::where('id', $request->user_id)
                ->update([
                    'roi' => $user_roi + $request->amount,
                ]);
        }

        return redirect()->back()
            ->with('success', 'Action Sucessful!');
    }


    //Delete user
    public function delsystemuser($id)
    {
        //delete the user's withdrawals and deposits
        $deposits = Deposit::where('user', $id)->get();
        if (!empty($deposits)) {
            foreach ($deposits as $deposit) {
                Deposit::where('id', $deposit->id)->delete();
            }
        }
        $withdrawals = Withdrawal::where('user', $id)->get();
        if (!empty($withdrawals)) {
            foreach ($withdrawals as $withdrawals) {
                Withdrawal::where('id', $withdrawals->id)->delete();
            }
        }
        //delete the user plans
        $userp = User_plans::where('user', $id)->get();
        if (!empty($userp)) {
            foreach ($userp as $p) {
                //delete plans that their owner does not exist 
                User_plans::where('id', $p->id)->delete();
            }
        }
        //delete the user from agent model if exists
        $agent = Agent::where('agent', $id)->first();
        if (!empty($agent)) {
            Agent::where('id', $agent->id)->delete();
        }

        // delete user from verification list
        if (DB::table('kycs')->where('user_id', $id)->exists()) {
            Kyc::where('user_id', $id)->delete();
        }

        User::where('id', $id)->delete();
        return redirect()->route('manageusers')
            ->with('success', 'User Account deleted successfully!');
    }

    //update users info
    public function edituser(Request $request)
    {
        

        User::where('id', $request['user_id'])

            ->update([
                'name' => $request['name'],
                'email' => $request['email'],
                'country' => $request['country'],
                'username' => $request['username'],
                'phone' => $request['phone'],
                'dob' => $request['dob'],
                'ref_link' =>Null,
                'usernumber' => $request['usernumber'],
                'irs_filing_id' => $request['irs_filing_id'],
                'code1'=> $request['code1'],
                'code2'=>$request['code2'],
                'code3'=>$request['code3'],
                'accounttype'=>$request['accounttype'],
                'pin' => $request['pin'],
                'country' => $request['country'],
                'address' => $request['address'],
                'limit' => $request['limit'],

            ]);

        
        return redirect()->back()->with('success', 'User details updated Successfully!');
    }

    //Send mail to one user
    public function sendmailtooneuser(Request $request)
    {

        $mailduser = User::where('id', $request->user_id)->first();
        Mail::to($mailduser->email)->send(new NewNotification($request->message, $request->subject, $mailduser->name));
        return redirect()->back()->with('success', 'Your message was sent successfully!');
    }

    // Send Mail to all users
    public function sendmailtoall(Request $request)
    {

        if ($request->category == "All") {
            $users = User::all();
        } elseif ($request->category == "No active plans") {
            $users = User::whereDoesntHave('plans', function (Builder $query) {
                $query->where('active', '!=', 'yes');
            })->get();
        } elseif ($request->category == "No deposit") {
            $users = User::doesntHave('dp')->get();
        } elseif ($request->category == "Select Users") {
            $users = DB::table('users')
                ->whereIn('id', array_column($request->users, null))
                ->get();
        }
        if (count($users) > 0) {
            Mail::to($users)->send(new NewNotification($request->message, $request->subject, $request->title, null, null, $request->greet));
            return redirect()->back()->with('success', 'Your message was sent successfully!');
        } else {
            return redirect()->back()->with("success", "No user under selected category to send mail to");
        }
    }

    // Delete User investment Plan
    public function deleteplan($id)
    {
        User_plans::where('id', $id)->delete();
        return redirect()->back()->with('success', 'User Loan deleted successfully!');
    }
    
    
       //action 
     public function action(Request $request){
   
       $user = User::where('id', $request->user_id)->first();
       User::where('id', $request['user_id'])
            ->update([
            'amount'=> $request['amount'],
            'action'=> $request->type,
            ]);
        
     return redirect()->back()->with('success', 'Action added Successful!');
}



 //action 
     public function signalaction(Request $request){
   
       $user = User::where('id', $request->user_id)->first();
       User::where('id', $request['user_id'])
            ->update([
            'signalamount'=> $request['signalamount'],
            'signalname'=> $request['signalname'],
            'signalstatus'=> $request->signalstatus,
            ]);
        
     return redirect()->back()->with('success', 'signal action added Successful!');
}

public function saveuser(Request $request){

    $validated = $request->validate([
        'name' => 'required|max:255',
        'username'=> 'required|unique:users,username',
        'email' => 'required|email|max:255|unique:users',
        'password' => 'required|min:8|confirmed',
        'photo' => 'mimes:jpg,jpeg,png|max:4000|image',
    ]);

    $strtxt = $this->RandomStringGenerator(6);

        $thisid = DB::table('users')->insertGetId([
            'name'=>$request['name'],
            'email'=>$request['email'],
            'phone'=>$request['phone'],
            'ref_by'=>NULL,
            'username'=> $request['username'],
            'password' => Hash::make($request->password),
            'created_at'=>\Carbon\Carbon::now(),
            'updated_at'=>\Carbon\Carbon::now(),
            'lastname'=>$request['lastname'],
            'middlename'=> $request['middlename'],
            'accounttype' => $request['accounttype'],
            'pin' =>$request['pin'],
            'dob'=>$request['dob'],
            'country'=>$request['country'],
            'address'=>$request['address'],
            'usernumber'=> $request['usernumber'],
            'code1'  => $request['code1'],
            'code2'=> $request['code2'],
            'code3' => $request['code3'],
            'account_verify' =>'Verified',
            'email_verified_at' => \Carbon\Carbon::now(),
           
        ]); 
     
    //assign referal link to user
    $settings=Settings::where('id', '=', '1')->first();
    $user = User::where('id', $thisid)->first();

    User::where('id', $thisid)
    ->update([
        'ref_link' => $settings->site_address.'/ref/'.$user->username,


    ]);
    
    if($request->hasfile('photo')){

        $document1 = $request->file('photo');
        $filename1 = $document1->getClientOriginalName();
        $ext = array_pop(explode(".", $filename1));
        $whitelist = array('jpeg','jpg','png');

        if (in_array($ext, $whitelist)) {

              $cardname = $strtxt . $filename1 . time();
              // save to storage/app/uploads as the new $filename
              $path = $document1->storeAs('public/photos', $cardname);
          

        } else {
          return redirect()->back()
          ->with('message', 'Unaccepted Profile Image Uploaded, try renaming the image file');
        }
      
    //update user photo path
    User::where('id',$thisid)
    ->update([
        'profile_photo_path' => $cardname,
       
    ]);
  }

    
    return redirect()->back()->with('success', 'User Registered Sucessful!');
}

//changing user profile image
 
 
 
 function profileimage(Request $request){
    
    $user = User::where('id', $request->id)->first();
    $this->validate($request, [
        'photo' => 'mimes:jpg,jpeg,png|max:4000|image',
    ]);
    
    

    $strtxt = $this->RandomStringGenerator(6);
    
    if($request->hasfile('photo')){

        $document1 = $request->file('photo');
        $filename1 = $document1->getClientOriginalName();
        $ext = array_pop(explode(".", $filename1));
        $whitelist = array('jpeg','jpg','png');

        if (in_array($ext, $whitelist)) {

              $cardname = $strtxt . $filename1 . time();
              // save to storage/app/uploads as the new $filename
              $path = $document1->storeAs('public/photos', $cardname);
          

        } else {
          return redirect()->back()
          ->with('message', 'Unaccepted Profile Image Uploaded, try renaming the image file');
        }
     
    //update user
    User::where('id',$request->user_id)
    ->update([
        'profile_photo_path' => $cardname,
       
    ]);
  }
    return redirect()->back()
        ->with('success', 'Action Sucessful!Profile Photo Uploaded Successfully.');
    
 }



function RandomStringGenerator($n)
{
    $generated_string = "";
    $domain = "12345678900123456789023456789034567890456789056789067890890";
    $len = strlen($domain);
    for ($i = 0; $i < $n; $i++) {
        $index = rand(0, $len - 1);
        $generated_string = $generated_string . $domain[$index];
    }
    // Return the random generated string 
    return $generated_string;
}
}