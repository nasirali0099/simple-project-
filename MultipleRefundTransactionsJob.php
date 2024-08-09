<?php

namespace App\Jobs;

use App\Events\MultipleRefundTransactionsEvent;
use App\Helpers\GeneralHelper;
use App\Library\Checkout;
use App\Library\Incharge;
use App\Library\Intergiro;
use App\Library\PaySafe;
use App\Library\StripeLibrary;
use App\Library\StripeRecurring;
use App\Library\WorldPay;
use App\Models\InprocessRefundTransaction;
use App\Models\PspLog;
use App\Models\RefundTransaction;
use App\Models\StatusCode;
use App\Models\Transaction;
use App\Repositories\BackOffice\RefundTransaction\RefundTransactionRepository;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MultipleRefundTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $refundTransactions;
    protected $authUserId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($refundTransactions, $authUserId)
    {
        $this->refundTransactions = $refundTransactions;
        $this->authUserId = $authUserId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $checkValue = false;
            $count = 0;
            foreach ($this->refundTransactions as $refundTransactionRecord) {
                $transaction = Transaction::where('id', $refundTransactionRecord->transaction_id)->with('psp', 'merchantAccount', 'User:id,full_name')->first();
                if (isset($transaction->is_test_card) && $transaction->is_test_card == 1) {
                    RefundTransactionRepository::updateRefundTransactionStatus($transaction, $refundTransactionRecord, null, $this->authUserId);
                } else {
                    switch ($transaction->psp->slug) {
                        case "intergiro":
                            $refunded = Intergiro::acqAuthCaptureOrRefund($transaction, 'refund');
                            if (isset($refunded->status) && $refunded->status == 'approved') {
                                self::createRefundTransaction($transaction, $refundTransactionRecord, $this->authUserId);
                                $checkValue = true;
                                $count++;
                            }
                            break;
                        case "checkout":
                            $refunded = Checkout::refundPayment($transaction->psp_transaction_id, $transaction->id, $transaction->id, $transaction->merchantAccount ?? null);
                            if (isset($refunded->reference)) {
                                self::createRefundTransaction($transaction, $refundTransactionRecord, $this->authUserId);
                                $checkValue = true;
                                $count++;
                            }
                            break;
                        case "worldpay":
                            $pspConvertedAmount = GeneralHelper::getPspSettledAmount($transaction->fiat_amount, $transaction->fiat_currency, $transaction->api_request_id, config("constants.WORLDPAY_SETTLED_CURRENCY"));
                            $amount = (int) bcmul($pspConvertedAmount, "100");
                            $urlData = GeneralHelper::connectStagingPspToProd($transaction->merchantAccount ?? null, 'WORLDPAY');
                            $refunded = WorldPay::refundPayment($transaction->id, $amount, $transaction->merchant_account_id, $urlData);
                            if (isset($refunded['reply']['ok']) && isset($refunded['reply']['ok']['refundReceived'])) {
                                InprocessRefundTransaction::create([
                                    'transaction_id' => $transaction->id,
                                    'status' => 'SENT_FOR_REFUND',
                                    'step' => 1,
                                    'brand_request' => 1,
                                    'directly_refunded_by' => $this->authUserId,
                                ]);
                                $refundTransactionRecord->update([
                                    'status' => RefundTransaction::INPROCESS,
                                    'admin_id' => $this->authUserId,
                                ]);
                                $checkValue = true;
                                $count++;
                                if (isset($refundTransactionRecord->callback_url)) {
                                    response()->success_callback(RefundTransaction::INPROCESS, $transaction->api_request_id, $transaction->id, $transaction->request_reference, 'Transaction Sent For Refunded', $refundTransactionRecord->callback_url);
                                }
                            }
                            break;
                        case "paysafe":
                            $pspLog = PspLog::where(['transaction_id' => $transaction->id, 'endpoint' => "make-payments"])->first(['transaction_id', 'endpoint', 'response_data']);
                            if ($pspLog) {
                                $responseData = json_decode($pspLog->response_data);
                                if ($responseData && isset($responseData->settlements) && is_array($responseData->settlements) && isset($responseData->settlements[0]->id)) {
                                    $urlData = GeneralHelper::connectStagingPspToProd($transaction->merchantAccount ?? null, 'PAYSAFE');
                                    $processRefund = PaySafe::processRefund($transaction, $urlData, $responseData->settlements[0]->id);
                                    if (isset($processRefund['id']) && isset($processRefund['status']) && $processRefund['status'] === 'PENDING') {
                                        InprocessRefundTransaction::create([
                                            'transaction_id' => $transaction->id,
                                            'status' => 'PENDING',
                                            'step' => 1,
                                            'brand_request' => 1,
                                            'directly_refunded_by' => $this->authUserId,
                                        ]);
                                        $refundTransactionRecord->update([
                                            'status' => RefundTransaction::INPROCESS,
                                            'admin_id' => $this->authUserId,
                                        ]);
                                        $checkValue = true;
                                        $count++;
                                        if (isset($refundTransactionRecord->callback_url)) {
                                            response()->success_callback(RefundTransaction::INPROCESS, $transaction->api_request_id, $transaction->id, $transaction->request_reference, 'Transaction Sent For Refunded', $refundTransactionRecord->callback_url);
                                        }
                                    }
                                }
                            }
                            break;
                        case "stripe":
                            if ($transaction->payment_method_flow == 'card-recurring') {
                                $refundPayment = StripeRecurring::refundRecurringPayment($transaction);
                                if (isset($refundPayment['status']) && $refundPayment['status'] == 'succeeded') {
                                    self::createRefundTransaction($transaction, $refundTransactionRecord, $this->authUserId);
                                    $checkValue = true;
                                    $count++;
                                }
                            } else {
                                $refundPayment = StripeLibrary::refundPayment($transaction);
                                if (isset($refundPayment['status']) && $refundPayment['status'] == 'succeeded') {
                                    self::createRefundTransaction($transaction, $refundTransactionRecord, $this->authUserId);
                                    $checkValue = true;
                                    $count++;
                                }
                            }
                            break;
                        case "cl-20":
                            $payload = [
                                "payin_reference" => $transaction->incharge_req_uuid,
                                "brand" => $transaction->User->full_name,
                                "request_id" => $transaction->request_token,
                            ];
                            $refundResponse = Incharge::refundTranx($payload, $transaction->id, $transaction->merchantAccount->secret_key, $transaction->merchantAccount ?? null);
                            if ($refundResponse->getStatusCode() == 200) {
                                self::createRefundTransaction($transaction, $refundTransactionRecord, $this->authUserId);
                                $checkValue = true;
                                $count++;
                                ClSuccessCallback::dispatch('refunded', $transaction->api_request_id, $transaction->id, $transaction->request_reference, 'Transaction Refunded')->onQueue('high_callbacks');
                            }
                            break;
                    }
                    if (!$checkValue) {
                        self::handleDeclineCase($refundTransactionRecord, $this->authUserId);
                    }
                }
            }
            // Events
            if ($count > 0) {
                event(new MultipleRefundTransactionsEvent("Total " . $count . " out of " . count($this->refundTransactions) . ngettext(' transaction', ' transactions', $count) ." refunded successfully", 'success', 'completed', $this->authUserId));
            } else {
                event(new MultipleRefundTransactionsEvent("Transactions cannot be refunded!", 'error', 'completed', $this->authUserId));
            }

        } catch (Exception $e) {
            return errorLogs(__METHOD__, $e->getLine(), $e->getMessage());
        }
    }

    /**
     * This function is use to update request refund transaction status.
     * @param $refundTransactionRecord
     * @param $authUserId
     * @return void
     */
    private static function handleDeclineCase($refundTransactionRecord, $authUserId)
    {
        try {
            $refundTransactionRecord->update(['status' => RefundTransaction::DECLINED, 'admin_id' => $authUserId, 'max_tries' => $refundTransactionRecord->max_tries + 1, 'read_status' => 0]);
        } catch (Exception $e) {
            return errorLogs(__METHOD__, $e->getLine(), $e->getMessage());
        }
    }

    /**
     * This function is use to create new refund transaction & send callback.
     * @param $transaction
     * @param $refundTransactionRecord
     * @param $authUserId
     * @return void
     */
    private static function createRefundTransaction($transaction, $refundTransactionRecord, $authUserId)
    {
        try {
            RefundTransactionRepository::updateRefundTransactionStatus($transaction, $refundTransactionRecord, null, $authUserId);
            if (isset($refundTransactionRecord->callback_url)) {
                response()->success_callback(StatusCode::REFUNDED, $transaction->api_request_id, $transaction->id, $transaction->request_reference, 'Transaction Refunded', $refundTransactionRecord->callback_url);
            }
        } catch (Exception $e) {
            return errorLogs(__METHOD__, $e->getLine(), $e->getMessage());
        }
    }
}
