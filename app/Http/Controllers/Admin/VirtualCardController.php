<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Card;
use App\Models\CardTransaction;
use App\Models\User;
use App\Models\Settings;
use App\Helpers\NotificationHelper;
use App\Models\CardSettings;

class VirtualCardController extends Controller
{
    // Display all cards 
    public function index()
    {
        $cards = Card::with('user')->latest()->paginate(20);
        return view('admin.cards.index', [
            'title' => 'Manage Virtual Cards',
            'cards' => $cards,
            'settings' => Settings::where('id', '1')->first(),
        ]);
    }

    // Display pending card applications
    public function pending()
    {
        $cards = Card::with('user')->where('status', 'pending')->latest()->paginate(20);
        return view('admin.cards.pending', [
            'title' => 'Pending Card Applications',
            'cards' => $cards,
            'settings' => Settings::where('id', '1')->first(),
        ]);
    }

    // View a specific card's details
    public function viewCard($id)
    {
        $card = Card::with('user')->findOrFail($id);
        $transactions = CardTransaction::where('card_id', $id)->latest()->paginate(10);
        
        return view('admin.cards.view', [
            'title' => 'Card Details',
            'card' => $card,
            'transactions' => $transactions,
            'settings' => Settings::where('id', '1')->first(),
        ]);
    }

    // Approve a card application
    public function approveCard($id)
    {
        $card = Card::findOrFail($id);
        $user = User::findOrFail($card->user_id);
        
        // Generate card details based on card type
        $cardDetails = $this->generateCardDetails($card->card_type);
        
        // Update card with generated details
        $card->card_number = $cardDetails['card_number'];
        $card->card_holder_name = $user->name;
        $card->expiry_month = $cardDetails['expiry_month'];
        $card->expiry_year = $cardDetails['expiry_year'];
        $card->cvv = $cardDetails['cvv'];
        $card->last_four = $cardDetails['last_four'];
        $card->bin = $cardDetails['bin'];
        $card->card_pan = $cardDetails['card_pan']; // Encrypted full card number
        $card->card_token = $cardDetails['card_token'];
        $card->status = 'active';
        $card->save();
        
        // Create notification
        NotificationHelper::create(
            $user,
            'Your ' . ucfirst($card->card_level) . ' ' . ucfirst(str_replace('_', ' ', $card->card_type)) . ' card has been approved and is now ready for use.',
            'Card Application Approved',
            'success',
            'check-circle',
            route('cards.view', $card->id)
        );
        
        return redirect()->back()->with('success', 'Card has been approved successfully');
    }

    // Reject a card application
    public function rejectCard($id)
    {
        $card = Card::findOrFail($id);
        $user = User::findOrFail($card->user_id);
        
        // Update card status
        $card->status = 'rejected';
        $card->save();
        
        // Create notification
        NotificationHelper::create(
            $user,
            'Your ' . ucfirst($card->card_level) . ' ' . ucfirst(str_replace('_', ' ', $card->card_type)) . ' card application has been rejected. Please contact support for more information.',
            'Card Application Rejected',
            'danger',
            'x-circle',
            route('cards.view', $card->id)
        );
        
        return redirect()->back()->with('success', 'Card has been rejected');
    }

    // Block a card
    public function blockCard($id)
    {
        $card = Card::findOrFail($id);
        $user = User::findOrFail($card->user_id);
        
        // Update card status
        $card->status = 'blocked';
        $card->save();
        
        // Create notification
        NotificationHelper::create(
            $user,
            'Your ' . ucfirst($card->card_level) . ' ' . ucfirst(str_replace('_', ' ', $card->card_type)) . ' card has been blocked. Please contact support for more information.',
            'Card Blocked',
            'danger',
            'lock',
            route('cards.view', $card->id)
        );
        
        return redirect()->back()->with('success', 'Card has been blocked');
    }
    
    // Unblock a card
    public function unblockCard($id)
    {
        $card = Card::findOrFail($id);
        $user = User::findOrFail($card->user_id);
        
        // Update card status
        $card->status = 'active';
        $card->save();
        
        // Create notification
        NotificationHelper::create(
            $user,
            'Your ' . ucfirst($card->card_level) . ' ' . ucfirst(str_replace('_', ' ', $card->card_type)) . ' card has been unblocked and is now active again.',
            'Card Unblocked',
            'success',
            'unlock',
            route('cards.view', $card->id)
        );
        
        return redirect()->back()->with('success', 'Card has been unblocked');
    }
    
    // Top up a card
    public function topupCard(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:255',
        ]);
        
        $card = Card::findOrFail($id);
        $user = User::findOrFail($card->user_id);
        
        // Update card balance
        $card->balance += $request->amount;
        $card->save();
        
        // Create transaction record
        CardTransaction::create([
            'card_id' => $card->id,
            'user_id' => $card->user_id,
            'amount' => $request->amount,
            'currency' => $card->currency,
            'transaction_type' => 'topup',
            'transaction_reference' => 'TOP' . time() . mt_rand(1000, 9999),
            'merchant_name' => config('app.name'),
            'status' => 'completed',
            'description' => $request->description ?? 'Admin card top-up',
            'transaction_date' => now(),
        ]);
        
        // Create notification
        NotificationHelper::create(
            $user,
            'Your ' . ucfirst($card->card_level) . ' ' . ucfirst(str_replace('_', ' ', $card->card_type)) . ' card has been credited with ' . $card->currency . $request->amount . '.',
            'Card Top-up',
            'success',
            'trending-up',
            route('cards.view', $card->id)
        );
        
        return redirect()->back()->with('success', 'Card has been topped up successfully');
    }
    
    // Deduct from a card
    public function deductCard(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:255',
        ]);
        
        $card = Card::findOrFail($id);
        $user = User::findOrFail($card->user_id);
        
        // Check if card has sufficient balance
        if ($card->balance < $request->amount) {
            return redirect()->back()->with('error', 'Insufficient card balance');
        }
        
        // Update card balance
        $card->balance -= $request->amount;
        $card->save();
        
        // Create transaction record
        CardTransaction::create([
            'card_id' => $card->id,
            'user_id' => $card->user_id,
            'amount' => -$request->amount,
            'currency' => $card->currency,
            'transaction_type' => 'deduction',
            'transaction_reference' => 'DED' . time() . mt_rand(1000, 9999),
            'merchant_name' => config('app.name'),
            'status' => 'completed',
            'description' => $request->description ?? 'Admin card deduction',
            'transaction_date' => now(),
        ]);
        
        // Create notification
        NotificationHelper::create(
            $user,
            'Your ' . ucfirst($card->card_level) . ' ' . ucfirst(str_replace('_', ' ', $card->card_type)) . ' card has been debited with ' . $card->currency . $request->amount . '.',
            'Card Deduction',
            'warning',
            'trending-down',
            route('cards.view', $card->id)
        );
        
        return redirect()->back()->with('success', 'Amount deducted from card successfully');
    }

    // Delete a card
    public function deleteCard($id)
    {
        $card = Card::findOrFail($id);
        $user = User::findOrFail($card->user_id);
        
        // Delete card transactions
        CardTransaction::where('card_id', $id)->delete();
        
        // Delete card
        $card->delete();
        
        // Create notification
        NotificationHelper::create(
            $user,
            'Your ' . ucfirst($card->card_level) . ' ' . ucfirst(str_replace('_', ' ', $card->card_type)) . ' card has been deleted from the system.',
            'Card Deleted',
            'danger',
            'trash-2',
            route('cards')
        );
        
        return redirect()->route('admin.cards')->with('success', 'Card has been deleted successfully');
    }

    /**
     * Generate card details based on card type
     * 
     * @param string $cardType
     * @return array
     */
    private function generateCardDetails($cardType)
    {
        // Set expiry date (current month + 3 years)
        $expiryMonth = str_pad(date('n'), 2, '0', STR_PAD_LEFT);
        $expiryYear = date('Y') + 3;
        
        // Generate CVV (3 digits for most cards, 4 digits for Amex)
        $cvvLength = (strtolower($cardType) == 'amex' || strtolower($cardType) == 'american_express') ? 4 : 3;
        $cvv = '';
        for ($i = 0; $i < $cvvLength; $i++) {
            $cvv .= mt_rand(0, 9);
        }
        
        // Generate card number based on card type
        $cardNumber = '';
        $bin = '';
        
        if (strpos(strtolower($cardType), 'visa') !== false) {
            // Visa cards start with 4 and have 16 digits
            $bin = '4' . $this->generateRandomDigits(5);
            $cardNumber = $bin . $this->generateRandomDigits(10);
        } elseif (strpos(strtolower($cardType), 'mastercard') !== false) {
            // Mastercard starts with 51-55 and has 16 digits
            $bin = (51 + mt_rand(0, 4)) . $this->generateRandomDigits(4);
            $cardNumber = $bin . $this->generateRandomDigits(10);
        } elseif (strpos(strtolower($cardType), 'american_express') !== false || strpos(strtolower($cardType), 'american_express') !== false) {
            // American Express starts with 34 or 37 and has 15 digits
            $bin = (mt_rand(0, 1) ? '34' : '37') . $this->generateRandomDigits(4);
            $cardNumber = $bin . $this->generateRandomDigits(9);
        } elseif (strpos(strtolower($cardType), 'discover') !== false) {
            // Discover starts with 6011 and has 16 digits
            $bin = '6011' . $this->generateRandomDigits(2);
            $cardNumber = $bin . $this->generateRandomDigits(10);
        } else {
            // Default to a generic 16-digit card number
            $bin = '9' . $this->generateRandomDigits(5);
            $cardNumber = $bin . $this->generateRandomDigits(10);
        }
        
        // Apply Luhn algorithm to make the card number valid (last digit is check digit)
        $cardNumber = $this->applyLuhnAlgorithm($cardNumber);
        
        // Get last four digits
        $lastFour = substr($cardNumber, -4);
        
        // Generate a unique token
        $cardToken = md5($cardNumber . time() . mt_rand());
        
        // Encrypt the full card number for storage (in a real system you'd use proper encryption)
        // Here we're just simulating encryption with base64 encode - in production use proper encryption
        $cardPan = base64_encode($cardNumber);
        
        return [
            'card_number' => $cardNumber,
            'expiry_month' => $expiryMonth,
            'expiry_year' => $expiryYear,
            'cvv' => $cvv,
            'last_four' => $lastFour,
            'bin' => substr($cardNumber, 0, 6),
            'card_pan' => $cardPan,
            'card_token' => $cardToken,
        ];
    }
    
    /**
     * Generate random digits of specified length
     * 
     * @param int $length
     * @return string
     */
    private function generateRandomDigits($length)
    {
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= mt_rand(0, 9);
        }
        return $digits;
    }
    
    /**
     * Apply Luhn algorithm to make the card number valid
     * 
     * @param string $cardNumber
     * @return string
     */
    private function applyLuhnAlgorithm($cardNumber)
    {
        // Remove the last digit if the card number already has the correct length
        // for the specific card type (16 for most, 15 for Amex)
        $numberWithoutCheckDigit = substr($cardNumber, 0, -1);
        
        // Calculate the check digit
        $sum = 0;
        $length = strlen($numberWithoutCheckDigit);
        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int)$numberWithoutCheckDigit[$i];
            if (($length - $i) % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }
        
        $checkDigit = (10 - ($sum % 10)) % 10;
        
        // Return the full card number with the check digit
        return $numberWithoutCheckDigit . $checkDigit;
    }

    /**
     * Display the card settings page.
     *
     * @return \Illuminate\Http\Response
     */
    public function settings()
    {
        $cardSettings = CardSettings::first();
        return view('admin.cards.settings', [
            'title' => 'Virtual Card Settings',
            'cardSettings' => $cardSettings,
        ]);
    }

    /**
     * Update card settings.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'standard_fee' => 'required|numeric|min:0',
            'gold_fee' => 'required|numeric|min:0',
            'platinum_fee' => 'required|numeric|min:0',
            'black_fee' => 'required|numeric|min:0',
            'monthly_fee' => 'required|numeric|min:0',
            'topup_fee_percentage' => 'required|numeric|min:0|max:100',
            'max_daily_limit' => 'required|numeric|min:0',
            'min_daily_limit' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $cardSettings = CardSettings::first();
        $cardSettings->update($request->all());

        return redirect()->route('admin.cards.settings')->with([
            'message' => 'Card settings updated successfully!',
            'type' => 'success',
        ]);
    }

    /**
     * Toggle card feature status.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function toggleCardStatus(Request $request)
    {
        $cardSettings = CardSettings::first();
        $cardSettings->is_enabled = !$cardSettings->is_enabled;
        $cardSettings->save();

        $status = $cardSettings->is_enabled ? 'enabled' : 'disabled';
        return redirect()->route('admin.cards.settings')->with([
            'message' => "Virtual cards feature has been {$status}!",
            'type' => 'success',
        ]);
    }
} 