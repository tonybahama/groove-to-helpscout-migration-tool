<?php

namespace App\Console\Commands;

use DateTime;
use GrooveHQ\Client as GrooveClient;
use HelpScout\ApiClient;
use HelpScout\ApiException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Log;

class SyncCommandBase extends Command
{
    /**
     * @var $requests_processed_this_minute array
     */
    private static $requests_processed_this_minute = array(
        GROOVE => 0,
        HELPSCOUT => 0
    );

    /**
     * @var $start_of_minute_timestamp array
     */
    private static $start_of_minute_timestamp = array(
        GROOVE => 0,
        HELPSCOUT => 0
    );
    /**
     * @var $rate_limits array
     */
    private static $rate_limits = array();

    private $grooveClient;
    private $helpscoutClient;

    /**
     * @var ProgressBar
     */
    protected $progressBar;

    public function __construct()
    {
        parent::__construct();

        $this->grooveClient = new GrooveClient(config('services.groove.key'));
        $this->helpscoutClient = ApiClient::getInstance();

        try {
            $this->helpscoutClient->setKey(config('services.helpscout.key'));
        } catch (ApiException $e) {
            $this->error("There was an error creating the HelpScout client. Message was: " . APIHelper::formatApiExceptionArray($e));
            return;
        }

        self::$rate_limits[GROOVE] = config('services.groove.ratelimit');
        self::$rate_limits[HELPSCOUT] = config('services.helpscout.ratelimit');
    }

    public function createProgressBar($total_units)
    {
        $this->progressBar = $this->output->createProgressBar($total_units);
        $this->progressBar->setFormat('%current%/%max% [%bar%] %percent%% %elapsed%/%estimated% | %message%');
        $this->progressBar->setMessage('');
    }

    /**
     * @return ApiClient
     */
    public function getHelpScoutClient() {
        return $this->helpscoutClient;
    }

    /**
     * @return ProgressBar
     */
    public function getProgressBar() {
        return $this->progressBar;
    }

    /**
     * @return GrooveClient
     */
    public function getGrooveClient()
    {
        return $this->grooveClient;
    }

    // COMMAND OVERRIDES
    // Intention: Clear the progress bar prior to invoking any of the command console's output methods
    // Re-display the progress bar after rendering the output line
    // Log the output string to Laravel's Monolog log

    private function isProgressBarActive() {
        return $this->getProgressBar()
            && $this->getProgressBar()->getMaxSteps() !== $this->getProgressBar()->getProgress();
    }

//    public function info($string, $verbosity = null) {
//        if ($this->isProgressBarActive()) { $this->getProgressBar()->clear(); parent::info(''); }
//        parent::info($string, $verbosity); // this implicitly calls $this->line()
//        if ($this->isProgressBarActive()) { $this->getProgressBar()->display(); }
//    }

    public function line($string, $style = null, $verbosity = null) {
        parent::line($string, $style, $verbosity);
        $logString = trim($string);
        switch ($style) {
            case 'comment':
            case 'question':
                Log::debug($logString);
                break;
            case 'info':
                Log::info($logString);
                break;
            case 'error':
                Log::error($logString);
                break;
            case 'warning':
                Log::warning($logString);
                break;
            default:
                Log::debug($logString);
                break;
        }
    }

    /*
    public function comment($string, $verbosity = null) {
        parent::comment($string, $verbosity); // implicitly calls $this->line()
    }

    public function question($string, $verbosity = null) {
        parent::question($string, $verbosity); // implicitly calls $this->line()
    }

    public function error($string, $verbosity = null) {
        parent::error($string, $verbosity); // implicitly calls $this->line()
    }

    public function warn($string, $verbosity = null) {
        parent::warn($string, $verbosity); // implicitly calls $this->line()
    }
    */

    /**
     * TODO change interface to method passing in configuration object (which is validated)
     *
     * Perform a rate-limited API call. The flow is:
     * 1. requestFunction()
     * 2. processFunction() based on requestFunction result
     * 3. publishFunction() based on processFunction result
     *
     * Only requestFunction() and serviceName are required fields.
     *
     * @param $serviceName
     * @param callable $requestFunction should return a list for processing
     * @param callable $processFunction must return a list of models for publishing
     * @param callable $publishFunction method to upload models; responsible for handling publication failures
     * @return mixed
     */
    public function makeRateLimitedRequest($serviceName, $requestFunction, $processFunction = null, $publishFunction = null) {
        $rateLimit = self::$rate_limits[$serviceName];
        if (SyncCommandBase::$requests_processed_this_minute[$serviceName] >= $rateLimit) {
            $seconds_to_sleep = 60 - (time() - SyncCommandBase::$start_of_minute_timestamp[$serviceName]);
            if ($seconds_to_sleep > 0) {
                $this->progressBar->setMessage("Rate limit reached for '$serviceName'. Waiting $seconds_to_sleep seconds.");
                $this->progressBar->display();
                sleep($seconds_to_sleep);
                $this->progressBar->setMessage("");
            }
            SyncCommandBase::$start_of_minute_timestamp[$serviceName] = time();
            SyncCommandBase::$requests_processed_this_minute[$serviceName] = 0;
        } elseif (time() - SyncCommandBase::$start_of_minute_timestamp[$serviceName] > 60) {
            SyncCommandBase::$start_of_minute_timestamp[$serviceName] = time();
            SyncCommandBase::$requests_processed_this_minute[$serviceName] = 0;
        }
        $response = $requestFunction();
        SyncCommandBase::$requests_processed_this_minute[$serviceName]++;
        if ($processFunction != null) {
            /** @var callable $processFunction */
            $processedModels = $processFunction($response);

            if ($publishFunction != null) {
                /** @var callable $publishFunction */
                $publishFunction($processedModels);
            }
        } else {
            // don't do anything
        }
        return $response;
    }

    /**
     * Outputs an ETA display based on current progress
     *
     * @param $startTime DateTime
     * @param $startPage int
     * @param $pageNumber int
     * @param $totalPages int
     */
    protected function displayETA($startTime, $startPage, $pageNumber, $totalPages)
    {
        if ($pageNumber === $startPage) {
            $this->info('Approximate ETA: TBD');
            return;
        }

        $pagesProcessed = $pageNumber - $startPage;
        $remaining = $totalPages - $pageNumber + 1;
        $now = new DateTime();
        $secondsDiff = $now->getTimestamp() - $startTime->getTimestamp();
        $secondsPerPage = $secondsDiff / $pagesProcessed;
        $timeRemaining = $secondsPerPage * $remaining;

        $hours = floor($timeRemaining / 3600);
        $minutes = floor($timeRemaining / 60) % 60;
        $seconds = $timeRemaining % 60;
        $this->info("Approximate ETA: " . sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds) . " based on $remaining page" . ($remaining == 1 ? "" : "s") . " remaining.");
    }
}
