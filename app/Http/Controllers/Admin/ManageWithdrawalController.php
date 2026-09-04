<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Settings;
use App\Models\Wdmethod;
use App\Models\Withdrawal;
use App\Mail\NewNotification;
use App\Traits\PingServer;
use App\Helpers\NotificationHelper;
use Illuminate\Support\Facades\Mail;

class ManageWithdrawalController extends Controller
{
    use PingServer;

    //process withdrawals
    public function pwithdrawal(Request $request)
    {
        $withdrawal=Withdrawal::where('id',$request->id)->first();
        $user=User::where('id',$withdrawal->user)->first();
       
        if($request->date!=' '){
         $created_at = $request->date;
         
        }else{
         $created_at = $withdrawal->$created_at;
        }
   
        if ($request->action == "Paid") {
            Withdrawal::where('id',$request->id)
            ->update([
                'status' => 'Processed',
                'created_at'=> $created_at,
            ]);

            // Create notification for successful withdrawal
            NotificationHelper::create(
                $user,
                'Your withdrawal request of ' . $withdrawal->amount . ' via ' . $withdrawal->payment_mode . ' has been processed and approved.',
                'Withdrawal Approved',
                'success',
                'check-circle',
                route('withdrawalsdeposits')
            );

            $settings=Settings::where('id', '=', '1')->first();
            $message = "This is to inform you that your transfer request of $settings->currency$withdrawal->amount  to  $withdrawal->accountname  have approved.";

            Mail::to($user->email)->send(new NewNotification($message, 'Successful Transfer', $user->name));
        }elseif($request->action == "On-hold"){
            Withdrawal::where('id',$request->id)
            ->update([
                'status' => 'On-hold',
                'created_at'=> $created_at,
            ]);

            // Create notification for on-hold withdrawal
            NotificationHelper::create(
                $user,
                'Your withdrawal request of ' . $withdrawal->amount . ' via ' . $withdrawal->payment_mode . ' has been placed on hold. Please contact support for more information.',
                'Withdrawal On Hold',
                'warning',
                'alert-triangle',
                route('withdrawalsdeposits')
            );

            $settings=Settings::where('id', '=', '1')->first();
            $message = "This is to inform you that your transfer request of $settings->currency$withdrawal->amount to $withdrawal->accountname is currently On-hold; contact support on $settings->contact_email for more details ";
     
            Mail::to($user->email)->send(new NewNotification($message, 'On-hold transfer Transction', $user->name));
        }
        else {

            if($withdrawal->user==$user->id){
                User::where('id',$user->id)
                ->update([
                    'account_bal' => $user->account_bal+$withdrawal->to_deduct,
                ]); 
               
               Withdrawal::where('id',$request->id)
            ->update([
                'status' => 'Rejected',
                'created_at'=> $created_at,
            ]);

                // Create notification for rejected withdrawal
                NotificationHelper::create(
                    $user,
                    'Your withdrawal request of ' . $withdrawal->amount . ' via ' . $withdrawal->payment_mode . ' has been rejected. The funds have been returned to your account.',
                    'Withdrawal Rejected',
                    'danger',
                    'x-circle',
                    route('withdrawalsdeposits')
                );

                if ($request->emailsend == "true") {
                    Mail::to($user->email)->send(new NewNotification($request->reason,$request->subject, $user->name));
                }
                
              }
            
        }

        return redirect()->route('mwithdrawals')->with('success', 'Action Sucessful!');
    }

    
    public function processwithdraw($id){
         $with = Withdrawal::where('id',$id)->first();
         $method = Wdmethod::where('name', $with->payment_mode)->first();
         $user = User::where('id', $with->user)->first();
        return view('admin.Withdrawals.pwithrdawal',[
            'withdrawal' => $with,
            'method' => $method,
            'user' => $user,
            'title'=>'Process withdrawal Request',
        ]);
    }
}