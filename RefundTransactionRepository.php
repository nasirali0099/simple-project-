<?php

     /**
     *
     * @param $request
     * @param $refundTransactionRepository
     * @return \Illuminate\Http\JsonResponse|mixed
     */
    public function multipleRefundTransactions(MultipleRefundTransactions $request, RefundTransactionRepository $refundTransactionRepository)
    {
        try {
            return $refundTransactionRepository->multipleRefundTransactions($request);
        } catch (Exception $e) {
            return errorLogs(__METHOD__, $e->getLine(), $e->getMessage());
        }
    }

	/**
     * @param  $request
     * @return \Illuminate\Http\JsonResponse|mixed|void
     */
    public function multipleRefundTransactions($request)
    {
        try {
            $refundTransactions = RefundTransaction::whereIn('transaction_id', $request->transaction_ids)->where('status','Pending')->get();
            if(count($refundTransactions) > 0) {
                event(new MultipleRefundTransactionsEvent('Please wait. Your refund requests are in progress.', 'success', 'initiated', auth()->user()->id));
                    dispatch(new MultipleRefundTransactionsJob($refundTransactions, auth()->user()->id))->onQueue('default');
                return response()->request_response(200, 'Transactions refunded successfully!');
            }
            return response()->request_response(400, 'Transactions cannot be refunded!');
        } catch (Exception $e) {
            return errorLogs(__METHOD__, $e->getLine(), $e->getMessage());
        }
    }

}