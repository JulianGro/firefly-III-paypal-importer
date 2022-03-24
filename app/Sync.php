<?php

namespace App;

use Carbon\Carbon;
use App\Models\Payer;
use App\Models\Transaction;

class Sync
{
    private PayPal $paypal;

    private Firefly $firefly;

    public function __construct()
    {
        $this->paypal  = new PayPal();
        $this->firefly = new Firefly();
    }

    // Loads transactions from PayPal and stores them
    public function syncPayPal(Carbon $date = null): void
    {
        if (is_null($date)) {
            $date = Carbon::now();
        }

        $records = $this->paypal->getTransactions($date);

        if (is_null($records)) {
            // We're done
            return;
        }

        // Create a database record for each record
        foreach ($records as $record) {
            $payer = null;

            // Only load payer info when its available
            if (isset($record->payer_info->account_id)) {
                $name = '';

                // Name is not always in the same field.
                if (! empty($record->payer_info->payer_name->alternate_full_name)) {
                    $name = $record->payer_info->payer_name->alternate_full_name;
                } elseif (! empty($record->payer_info->payer_name->given_name)) {
                    $name = $record->payer_info->payer_name->given_name;
                }

                $payer = Payer::updateOrCreate([
                    'pp_id' => $record->payer_info->account_id,
                ], [
                    'email'        => $record->payer_info->email_address ?? '',
                    'name'         => $name,
                    'country_code' => $record->payer_info->country_code ?? '',
                ]);
            }

            $reference = null;

            if (isset($record->transaction_info->paypal_reference_id)) {
                $reference = $record->transaction_info->paypal_reference_id;
            }

            // Start building description.
            $description = [];

            // Add the invoice_id if available
            if (isset($record->transaction_info->invoice_id)) {
                $description[] = $record->transaction_info->invoice_id;
            }

            if (isset($record->cart_info->item_details)) {
                $cartItems     = $record->cart_info->item_details;
                $description[] = implode(', ', array_column($cartItems, 'item_name'));
            }

            // Remove duplicates. item_details and invoice_id can be the same value.
            $description = array_unique($description);

            $transaction = Transaction::updateOrCreate([
                'pp_id' => $record->transaction_info->transaction_id,
            ], [
                'reference_id'    => $reference,
                'event_code'      => $record->transaction_info->transaction_event_code,
                'initiation_date' => $record->transaction_info->transaction_initiation_date,
                'currency'        => $record->transaction_info->transaction_amount->currency_code,
                'value'           => $record->transaction_info->transaction_amount->value,
                'description'     => implode(' | ', $description),
            ]);

            if (! is_null($payer)) {
                $transaction->payer()->associate($payer);
            } else {
                $transaction->payer_id = null;
            }
            $transaction->save();
        }

        $this->syncPayPal($date->copy()->subMonth());
    }

    public function syncFirefly()
    {
        $this->firefly->sync(Transaction::all());
    }
}
