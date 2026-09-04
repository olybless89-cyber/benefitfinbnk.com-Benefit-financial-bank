<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IrsRefund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IrsRefundController extends Controller
{
    public function index()
    {
        $refunds = IrsRefund::with('user')
            ->latest()
            ->paginate(10);
            
        return view('admin.irs-refunds.index', compact('refunds'));
    }

    public function pending()
    {
        $refunds = IrsRefund::with('user')
            ->where('status', 'pending')
            ->latest()
            ->paginate(10);
            
        return view('admin.irs-refunds.pending', compact('refunds'));
    }

    public function view($id)
    {
        $refund = IrsRefund::with('user')->findOrFail($id);
        return view('admin.irs-refunds.view', compact('refund'));
    }

    public function approve($id)
    {
        try {
            DB::beginTransaction();
            
            $refund = IrsRefund::findOrFail($id);
            $refund->status = 'approved';
            $refund->save();
            
            // Add any additional logic here (e.g., notifications, user balance updates)
            
            DB::commit();
            return redirect()->back()->with('success', 'Refund request approved successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Error approving refund request.');
        }
    }

    public function reject($id)
    {
        try {
            DB::beginTransaction();
            
            $refund = IrsRefund::findOrFail($id);
            $refund->status = 'rejected';
            $refund->save();
            
            // Add any additional logic here (e.g., notifications)
            
            DB::commit();
            return redirect()->back()->with('success', 'Refund request rejected successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Error rejecting refund request.');
        }
    }

    public function process($id)
    {
        try {
            DB::beginTransaction();
            
            $refund = IrsRefund::findOrFail($id);
            $refund->status = 'processed';
            $refund->save();
            
            // Add any additional logic here (e.g., notifications, user balance updates)
            
            DB::commit();
            return redirect()->back()->with('success', 'Refund processed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Error processing refund.');
        }
    }

    public function delete($id)
    {
        try {
            $refund = IrsRefund::findOrFail($id);
            $refund->delete();
            return redirect()->back()->with('success', 'Refund request deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error deleting refund request.');
        }
    }

    public function settings()
    {
        $irssettings = \App\Models\IrsRefundSetting::first();
        return view('admin.irs-refunds.settings', compact('irssettings'));
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'required|numeric|gt:min_amount',
            'processing_fee' => 'required|numeric|min:0|max:100',
            'processing_time' => 'required|integer|min:1',
            'instructions' => 'required|string',
            'enable_refunds' => 'boolean',
            'require_verification' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            $irssettings = \App\Models\IrsRefundSetting::first() ?? new \App\Models\IrsRefundSetting();
            
            $irssettings->min_amount = $request->min_amount;
            $irssettings->max_amount = $request->max_amount;
            $irssettings->processing_fee = $request->processing_fee;
            $irssettings->processing_time = $request->processing_time;
            $irssettings->instructions = $request->instructions;
            $irssettings->enable_refunds = $request->boolean('enable_refunds');
            $irssettings->require_verification = $request->boolean('require_verification');
            
            $irssettings->save();

            DB::commit();
            return redirect()->back()->with('success', 'Settings updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Error updating settings: ' . $e->getMessage());
        }
    }
} 