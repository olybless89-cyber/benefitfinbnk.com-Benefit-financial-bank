<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Mail\NewNotification;
use App\Models\Kyc;
use App\Helpers\NotificationHelper;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class KycController extends Controller
{

    public function processKyc(Request $request)
    {
        $application = Kyc::find($request->kyc_id);
        $user = User::where('id', $application->user_id)->first();

        // will use API key
        if ($request->action == 'Accept') {
            User::where('id', $user->id)
                ->update([
                    'account_verify' => 'Verified',
                ]);
            $application->status = "Verified";
            $application->save();
            
            // Create notification for approved KYC
            NotificationHelper::create(
                $user,
                'Your KYC verification has been reviewed and approved. Your account is now fully verified.',
                'KYC Verification Approved',
                'success',
                'check-circle',
                route('account.verify')
            );
        } else {
            if (Storage::disk('public')->exists($application->frontimg) and Storage::disk('public')->exists($application->backimg)) {
                Storage::disk('public')->delete($application->frontimg);
                Storage::disk('public')->delete($application->backimg);
            }

            // Update the user verification status
            $user->account_verify = 'Rejected';
            $user->save();
            
            // Create notification for rejected KYC
            NotificationHelper::create(
                $user,
                'Your KYC verification was not approved. Please review the requirements and submit new documents.',
                'KYC Verification Rejected',
                'danger',
                'x-circle',
                route('account.verify')
            );
            
            // delete the application form database so user can resubmit application
            $application->delete();
        }

        Mail::to($user->email)->send(new NewNotification($request->message, $request->subject, $user->name));
        return redirect()->route('kyc')->with('success', 'Action Sucessful!');
    }
}