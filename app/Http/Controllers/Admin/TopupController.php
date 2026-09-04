<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Tp_Transaction;
use App\Models\User;
use App\Traits\PingServer;
use Illuminate\Http\Request;
use App\Models\Withdrawal;
use App\Models\Settings;
use App\Mail\NewNotification;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Twilio\Rest\Client;

class TopupController extends Controller
{
   use PingServer;

    //top up route
    public function topup(Request $request)
    {
        
       
    $user = User::where('id', $request->user_id)->first();
        $userdpo = Deposit::where('user', $request['user_id'])->first();
        $settings = Settings::where('id', '1')->first();
        $user_bal=$user->account_bal;
        $user_bonus=$user->bonus;
        $user_roi=$user->roi;
        $user_Ref=$user->ref_bonus;
        $user_deposit = $userdpo->amount;

        $subtxn =substr(strtoupper($settings->site_name),0,4);
        $codetxn1 = $this->RandomStringGenerator(8);
        $codetxn2 = substr(strtoupper(Carbon::now()),0,4);

        if($request['t_type']=="Credit") {
            if ($request['type']=="Bonus") {
                User::where('id', $request['user_id'])
                ->update([
                'bonus'=> $user_bonus + $request['amount'],
                'account_bal'=> $user_bal + $request->amount,
                ]);
            }elseif ($request['type']=="Profit") {
                User::where('id', $request->user_id)
                ->update([
                    'roi'=> $user_roi + $request->amount,
                    'account_bal'=> $user_bal + $request->amount,
                ]);
            }elseif($request['type']=="Ref_Bonus"){
                User::where('id', $request->user_id)
                ->update([
                    'ref_bonus'=> $user_Ref + $request->amount,
                    'account_bal'=> $user_bal + $request->amount,
                ]);
            
            }elseif ($request['type']=="balance") {
        
                $dp=new Withdrawal();
                $dp->amount= $request['amount'];
                $dp->payment_mode = $request['scope'];
                $dp->Description = $request['Description'];
                $dp->created_at  = $request['date'];
                $dp->type    = $request['t_type'];
                $dp->accountname = 'Self';
                $dp->bankname = $settings->site_name;
                $dp->accountnumber = $user->usernumber;
                $dp->Accounttype = $user->accounttype;
                $dp->country  = $user->country;
                $dp->bankaddress = $settings->address;
                $dp->status= 'Processed';
                
                $dp->user= $request['user_id'];
                $dp->txn_id = "$subtxn/$codetxn1-$codetxn2";
                $dp->save();

                User::where('id', $request['user_id'])
                ->update([
                    'account_bal'=> $user_bal + $request->amount,
                ]);
                
                
                $bala =  $user_bal +$request->amount;
              
               if($request['notifymailuser']==1){
                 // send notification
         $settings=Settings::where('id', '=', '1')->first();
         $date  = Carbon::parse($dp->created_at)->toDayDateTimeString();
        $message = "Your account have been Credited.
        \r\nDetails of the transaction are shown below;
        \r\nAccount Number: $user->usernumber
        \r\nAccount Name: $user->name $user->middlename $user->lastname
         \r\nDescription: $request->Description
         \r\nTotal Amount:$request->amount$settings->s_currency
         \r\nDate: $date
         \r\nAvailable Balance:$bala$settings->s_currency";
           
        $subject ="Credit alert Notification[$request->amount$settings->s_currency]";
        Mail::to( $user->email)->send(new NewNotification($message, $subject, $user->name));

        
        if($settings->sms=='1'){
            $receiverNumber = $user->phone;
        $message = "Your account have been Credited.
        \nDetails of the transaction are shown below;
     \nAccount Number: $dp->accountnumber
     \nAccount Name: $user->name $user->middlename $user->lastname
     \nDescription: $request->Description
     \nTotal Amount:$request->amount$settings->s_currency
     \nDate:$date
     \nAvailable Balance:$bala$settings->s_currency";
  
        try {
  
            $account_sid = getenv("TWILIO_SID");
            $auth_token = getenv("TWILIO_TOKEN");
            $twilio_number = getenv("TWILIO_FROM");
  
            $client = new Client($account_sid, $auth_token);
            $client->messages->create($receiverNumber, [
                'from' => $twilio_number, 
                'body' => $message]);
  
          
  
        } catch (Exception $e) {
            
        }

        }




               }

            }
            
            //add history
            Tp_Transaction::create([
            'user' => $request->user_id,
            'plan' => "Credit",
            'amount'=>$request->amount,
            'type'=>$request->type,
            ]);
        
        }elseif($request['t_type']=="Debit") {
          if ($request['type']=="Bonus") {
            User::where('id', $request['user_id'])
              ->update([
                'bonus'=> $user_bonus - $request['amount'],
                'account_bal'=> $user_bal - $request->amount,
              ]);
          }elseif ($request['type']=="Profit") {
              User::where('id', $request->user_id)
                ->update([
                  'roi'=> $user_roi - $request->amount,
                  'account_bal'=> $user_bal - $request->amount,
                ]);
            }elseif($request['type']=="Ref_Bonus"){
              User::where('id', $request->user_id)
                ->update([
                  'Ref_Bonus'=> $user_Ref - $request->amount,
                  'account_bal'=> $user_bal - $request->amount,
                ]);
            }
            elseif($request['type']=="balance"){

                $dp=new Withdrawal();
                $dp->amount= $request['amount'];
                $dp->payment_mode = $request['scope'];
                $dp->Description = $request['Description'];
                $dp->created_at  = $request['date'];
                $dp->type    = $request['t_type'];
                $dp->accountname = 'Self';
                $dp->status= 'Processed';
                $dp->user= $request['user_id'];
                 $dp->bankname = $settings->bankname;
              $dp->accountnumber = $user->usernumber;
                $dp->Accounttype = $user->Accountype;
                $dp->country  = $user->country;
                $dp->bankaddress = $settings->address;
                $dp->txn_id = "$subtxn/$codetxn1-$codetxn2";
                $dp->save();
                
                User::where('id', $request->user_id)
                  ->update([
                    'account_bal'=> $user_bal - $request->amount,
                  ]);

                $bala =  $user_bal - $request->amount;
                  if($request['notifymailuser']==1){
                    // send notification
            $settings=Settings::where('id', '=', '1')->first();
            $date  = Carbon::parse($dp->created_at)->toDayDateTimeString();
           $message = "Your account have been Debited.
           \r\nDetails of the transaction are shown below;
           \r\nAccount Number: $user->usernumber
           \r\nAccount Name: $user->name $user->middlename $user->lastname
            \r\nDescription: $request->Description
            \r\nTotal Amount: $request->amount$settings->s_currency
            \r\nDate: $date
            \r\nAvailable Balance: $bala$settings->s_currency";
           $subject ="Debit alert Notification[$request->amount$settings->s_currency]";
           Mail::to( $user->email)->send(new NewNotification($message, $subject, $user->name ));
   
   
           if($settings->sms=='1'){
               $receiverNumber = $user->phone;
           $message = "Your account have been Debited.
           \nDetails of the transaction are shown below;
        \nAccount Number: $dp->accountnumber
        \nAccount Name: $user->name $user->middlename $user->lastname
        \nDescription: $request->Description
        \nTotal Amount: $request->amount$settings->s_currency
        \nDate:$date
        \nAvailable Balance:$bala$settings->s_currency";
     
           try {
     
               $account_sid = getenv("TWILIO_SID");
               $auth_token = getenv("TWILIO_TOKEN");
               $twilio_number = getenv("TWILIO_FROM");
     
               $client = new Client($account_sid, $auth_token);
               $client->messages->create($receiverNumber, [
                   'from' => $twilio_number, 
                   'body' => $message]);
     
             
     
           } catch (Exception $e) {
               
           }
   
           }
   
   
   
   
                  }
   

              }
            
             //add history
            Tp_Transaction::create([
                'user' => $request->user_id,
                'plan' => "Credit reversal",
                'amount'=>$request->amount,
                'type'=>$request->type,
            ]);
        
        }
        return redirect()->back()->with('success', 'Action Successful!');
    }
    function RandomStringGenerator($n)
    {
        $generated_string = "";
        $domain = "ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890";
        $len = strlen($domain);
        for ($i = 0; $i < $n; $i++) {
            $index = rand(0, $len - 1);
            $generated_string = $generated_string . $domain[$index];
        }
        // Return the random generated string 
        return $generated_string;
    }
}   
