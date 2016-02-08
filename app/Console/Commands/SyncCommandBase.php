<?php

namespace App\Console\Commands;

use HelpScout\ApiClient;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class SyncCommandBase extends Command
{
    public static $requests_processed_this_minute = 0;
    public static $start_of_minute_timestamp = 0;

    public $uploadQueue = array();

    protected $grooveClient;
    protected $helpscoutClient;

    /**
     * @var ProgressBar
     */
    public $progressBar;

    public function __construct()
    {
        parent::__construct();

        $this->grooveClient = new \GrooveHQ\Client(config('services.groove.key'));
        $this->helpscoutClient = ApiClient::getInstance();
    }

    public function createProgressBar($total_units)
    {
        $this->progressBar = $this->output->createProgressBar($total_units);
        $this->progressBar->setFormat('%current%/%max% [%bar%] %percent%% %elapsed%/%estimated% | %message%');
        $this->progressBar->setMessage('');
    }

    function addToQueue($jobs_list) {
        $this->uploadQueue = array_merge($this->uploadQueue, $jobs_list);
    }

    public function makeRateLimitedRequest($requestFunction, $processFunction = null, $rate_limit) {
        if (SyncCommandBase::$requests_processed_this_minute >= $rate_limit) {
            $seconds_to_sleep = 60 - (time() - SyncCommandBase::$start_of_minute_timestamp);
            if ($seconds_to_sleep > 0) {
                $this->progressBar->setMessage("Rate limit reached. Waiting $seconds_to_sleep seconds.");
                $this->progressBar->display();
                sleep($seconds_to_sleep);
                $this->progressBar->setMessage("");
            }
            SyncCommandBase::$start_of_minute_timestamp = time();
            SyncCommandBase::$requests_processed_this_minute = 0;
        } elseif (time() - SyncCommandBase::$start_of_minute_timestamp > 60) {
            SyncCommandBase::$start_of_minute_timestamp = time();
            SyncCommandBase::$requests_processed_this_minute = 0;
        }
        $response = $requestFunction();
        SyncCommandBase::$requests_processed_this_minute++;
        if ($processFunction != null) {
            /** @var callable $processFunction */
            $this->addToQueue($processFunction($response));
        } else {
            // don't do anything
        }
        return $response;
    }
}
