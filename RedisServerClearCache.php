<?php

namespace App\Console\Commands;

use App\Helpers\GeneralHelper;
use App\Models\RedisData;
use App\Models\Transaction;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class RedisServerClearCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:redis_server_clear_cache';
// daily traffic of around 90K to 1Lac Eur/day
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will clear redis server cache on after 15 days';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            ini_set('memory_limit', '-1');
            set_time_limit(0);
            $count = 0;
            $start_date = (new Carbon)->subDays(15)->startOfDay();
            $end_date = (new Carbon)->now()->endOfDay();

            Transaction::where('request_type', 'transaction')
                ->whereBetween('created_at', [$start_date, $end_date])
                ->chunkById(1000, function ($transactionData) use (&$count) {
                    $tokens = $transactionData->pluck('request_token')->toArray();

                    // Bulk delete RedisData entries
                    RedisData::whereIn('request_token', $tokens)->delete();

                    // Bulk delete from Redis
                    Redis::del($tokens);

                    $count += count($tokens);
                });

            // Send slack notification
            $url = config('constants.SLACK_WEBHOOK_URL');
            $headers = ["Content-Type: application/json"];
            $message['attachments'][] = [
                'title' => 'Redis Server Cache Cleaner ( ' . env('APP_ENV') . ' )',
                'text' => 'Total *' . $count . '* keys deleted from ' . $start_date . ' to ' . $end_date,
            ];
            GeneralHelper::curlRequest(true, json_encode($message), $url, $headers);
        } catch (Exception $e) {
            return errorLogs(__METHOD__, $e->getLine(), $e->getMessage());
        }
    }
}
