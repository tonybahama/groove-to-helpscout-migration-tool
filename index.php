<?php

require 'vendor/autoload.php';

// Parse configurations and define as constants
$ini_array = parse_ini_file("conf/keys.ini");
define("GROOVEHQ_API_KEY", $ini_array['groovehq_client_api_key']);
define("HELPSCOUT_API_KEY", $ini_array['helpscout_client_api_key']);
define("GROOVEHQ_REQUESTS_PER_MINUTE", intval($ini_array['groovehq_rate_limit']));
define("HELPSCOUT_REQUESTS_PER_MINUTE", intval($ini_array['helpscout_rate_limit']));

// FIXME: remove after development
$DEBUG_LIMIT = 5;

$requests_processed_this_minute = 0;
$start_of_minute_timestamp = time();
$uploadQueue = array();

// -------
// Acquire
// -------

// TODO: Move acquisition to its own module
$gh = new \GrooveHQ\Client(GROOVEHQ_API_KEY);

$agents_service = $gh->agents();
$customers_service = $gh->customers();
$messages_service = $gh->messages();
$tickets_service = $gh->tickets();

function makeRateLimitedRequest($requestFunction, $processFunction = null, $rate_limit) {
    global $requests_processed_this_minute, $start_of_minute_timestamp;
    if ($requests_processed_this_minute >= $rate_limit) {
        $seconds_to_sleep = 60 - (time() - $start_of_minute_timestamp);
        if ($seconds_to_sleep > 0) {
            // TODO: nicer formatting (maybe a viewer)
            echo "Rate limit reached. Waiting $seconds_to_sleep seconds. <br>";
            sleep($seconds_to_sleep);
        }
        $start_of_minute_timestamp = time();
        $requests_processed_this_minute = 0;
    } elseif (time() - $start_of_minute_timestamp > 60) {
        $start_of_minute_timestamp = time();
        $requests_processed_this_minute = 0;
    }
    $response = $requestFunction();
    $requests_processed_this_minute++;
    if ($processFunction != null) {
        /** @var callable $processFunction */
        addToQueue($processFunction($response));
    } else {
        // assume we get back a list of items
        addToQueue($response);
    }
    return $response;
}

function addToQueue($jobs_list) {
    global $uploadQueue;
    $uploadQueue = array_merge($uploadQueue, $jobs_list);
}

// Fetch all tickets
/*$page_number = 1;
do {
    $response = makeRateLimitedRequest(
        function () use ($tickets_service, $page_number) {
            return $tickets_service->list(['page' => $page_number, 'per_page' => 50])['tickets'];
        },
    // TODO: process tickets here
        null,
        GROOVEHQ_REQUESTS_PER_MINUTE);
    echo "Retrieved " . count($response) . " tickets from page " . $page_number . " <br>";
    $page_number++;
} while (count($response) > 0 && $page_number <= $DEBUG_LIMIT);
echo "Tickets acquired."
*/

// Fetch all customers
$page_number = 1;
$number_customers = 0;
do {
    $response = makeRateLimitedRequest(
        function () use ($customers_service, $page_number) {
            return $customers_service->list(['page' => $page_number, 'per_page' => 50])['customers'];
        },
        function ($customers_list) {
            $processed_customers = array();
            foreach ($customers_list as $groove_customer) {
                // Groove: email, name, about, twitter_username, title, company_name, phone_number, location, website_url, linkedin_username
                // HelpScout Customer (subset of Person): firstName, lastName, photoUrl, photoType, gender, age, organization, jobTitle, location, createdAt, modifiedAt
                // HelpScout Person: id, firstName, lastName, email, phone, type (user, customer, team)
                try {
                    $customer = new \HelpScout\model\Customer();

                    // Groove doesn't separate these fields
                    $full_name = $groove_customer['name'];
                    $spacePos = strpos($full_name, ' ');
                    if ($spacePos !== false) {
                        $customer->setFirstName(substr($full_name, 0, $spacePos));
                        $customer->setLastName((trim(substr($full_name, $spacePos + 1))));
                    } else {
                        $customer->setFirstName($full_name);
                    }

                    $customer->setOrganization($groove_customer['company_name']);
                    $customer->setJobTitle($groove_customer['title']);
                    $customer->setLocation($groove_customer['location']);
                    $customer->setBackground($groove_customer['about']);

                    // Groove doesn't have addresses

                    if ($groove_customer['phone_number'] != null) {
                        $phonenumber = new \HelpScout\model\customer\PhoneEntry();
                        $phonenumber->setValue($groove_customer['phone_number']);
                        $phonenumber->setLocation("home");
                        $customer->setPhones(array($phonenumber));
                    }

                    // Emails: at least one email is required
                    $emailHome = new \HelpScout\model\customer\EmailEntry();
                    $emailHome->setValue($groove_customer['email']);
                    $emailHome->setLocation("home");

                    $customer->setEmails(array($emailHome));

                    // Social Profiles (Groove supports Twitter and LinkedIn)
                    $social_profiles = array();
                    if ($groove_customer['twitter_username'] != null) {
                        $twitter = new \HelpScout\model\customer\SocialProfileEntry();
                        $twitter->setValue($groove_customer['twitter_username']);
                        $twitter->setType("twitter");
                        $social_profiles []= $twitter;
                    }

                    if ($groove_customer['linkedin_username'] != null) {
                        $linkedin = new \HelpScout\model\customer\SocialProfileEntry();
                        $linkedin->setValue($groove_customer['linkedin_username']);
                        $linkedin->setType("linkedin");
                        $social_profiles []= $linkedin;
                    }

                    $customer->setSocialProfiles($social_profiles);

                    // Groove doesn't have chats

                    if ($groove_customer['website_url'] != null) {
                        $website = new \HelpScout\model\customer\WebsiteEntry();
                        $website->setValue($groove_customer['website_url']);

                        $customer->setWebsites(array($website));
                    }

                    $processed_customers []= $customer;
                } catch (HelpScout\ApiException $e) {
                    echo $e->getMessage();
                    print_r($e->getErrors());
                }
            }
            return $processed_customers;
        },
        GROOVEHQ_REQUESTS_PER_MINUTE);
    echo "Retrieved " . count($response) . " customers from page " . $page_number . " <br>";
    $number_customers += count($response);
    $page_number++;
} while (count($response) > 0 && $page_number <= $DEBUG_LIMIT);
echo "$number_customers customers acquired.";

// -------
// Process
// -------

// TODO: map states and fields of data objects
function processAgents($groove_agents) {

}

function processCustomers($groove_customers) {
}

function processMessages($groove_messages) {
}

function processTickets($groove_tickets) {
    // statuses for Groove: unread, opened, pending, closed, spam
    // statuses for Help Scout: active, pending, closed, spam
}

// -------
// Publish
// -------

// TODO: move publish to its own module
use HelpScout\ApiClient;

$requests_processed_this_minute = 0;
$start_of_minute_timestamp = time();

// Create customers
try {
    $client = ApiClient::getInstance();
    $client->setKey(HELPSCOUT_API_KEY);

    foreach ($uploadQueue as $model) {
        if (strcasecmp(get_class($model), "Customer") === 0) {
            $client->createCustomer($model);
        }
    }
} catch (HelpScout\ApiException $e) {
    echo $e->getMessage();
    print_r($e->getErrors());
}

exit();


function processPublishJobQueue() {
    global $uploadQueue;
    foreach ($uploadQueue as $job) {
        $job->publish();
    }
}


// Task breakdown
// TODO: create queue of jobs to update so we don't spam the HelpScout connection
// TODO: determine rate limiting for API and batch jobs according to that ratio
// TODO: execute batches

// Nice-to-haves
// TODO: generate progress updater
// TODO: wizard for updating php.ini