<?php

namespace App\Console\Commands\Processors;
use App\Console\Commands\APIHelper;
use App\Console\Commands\SyncCommandBase;
use Closure;
use HelpScout\ApiException;
use HelpScout\model\Customer;
use HelpScout\model\customer\EmailEntry;
use HelpScout\model\customer\PhoneEntry;
use HelpScout\model\customer\SocialProfileEntry;
use HelpScout\model\customer\WebsiteEntry;

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2016-02-04
 * Time: 1:29 PM
 */
class CustomerProcessor implements ProcessorInterface
{
    private static $processor;

    /**
     * @param SyncCommandBase $consoleCommand
     * @return Closure
     */
    public static function getProcessor($consoleCommand = null)
    {
        if (null === static::$processor) {
            static::$processor = self::generateProcessor($consoleCommand);
        }

        return static::$processor;
    }

    /**
     * @param $consoleCommand SyncCommandBase
     * @return Closure
     */
    private static function generateProcessor($consoleCommand)
    {
        return function ($customersList) use ($consoleCommand) {
            $processedCustomers = array();
            foreach ($customersList as $grooveCustomer) {

                // Groove: email, name, about, twitter_username, title, company_name, phone_number, location, website_url, linkedin_username
                // HelpScout Customer (subset of Person): firstName, lastName, photoUrl, photoType, gender, age, organization, jobTitle, location, createdAt, modifiedAt
                // HelpScout Person: id, firstName, lastName, email, phone, type (user, customer, team)
                $grooveCustomerEmail = $grooveCustomer['email'];
                try {
                    // TODO: create hybrid model that contains both the Groove and Customer objects

                    $customer = new Customer();

                    // Groove doesn't separate these fields
                    /* @var $fullName string */
                    $fullName = $grooveCustomer['name'];
                    list($firstName, $lastName) = APIHelper::extractFirstAndLastNameFromFullName($fullName, $consoleCommand);
                    $customer->setFirstName($firstName);
                    $customer->setLastName($lastName);

                    // Organization must be 60 characters or less
                    $customerOrganization = $grooveCustomer['company_name'];
                    if ($customerOrganization && strlen($customerOrganization) > 60) {
                        $consoleCommand->warn("Warning: Customer organization \"$customerOrganization\" must be 60 characters or less. Truncating. (email: $grooveCustomerEmail)");
                        $customerOrganization = substr($customerOrganization, 0, 60);
                    }
                    $customer->setOrganization($customerOrganization);
                    
                    // Job title must be 60 characters or less
                    $customerJobTitle = $grooveCustomer['title'];
                    if ($customerJobTitle && strlen($customerJobTitle) > 60) {
                        $consoleCommand->warn("Warning: Customer job title \"$customerJobTitle\" must be 60 characters or less. Truncating. (email: $grooveCustomerEmail)");
                        $customerJobTitle = substr($customerJobTitle, 0, 60);
                    }
                    $customer->setJobTitle($customerJobTitle);
                    $customer->setLocation($grooveCustomer['location']);
                    $customer->setBackground($grooveCustomer['about']);

                    // Groove doesn't have addresses

                    if ($grooveCustomer['phone_number'] != null) {
                        $phonenumber = new PhoneEntry();
                        $phonenumber->setValue($grooveCustomer['phone_number']);
                        $phonenumber->setLocation("home");
                        $customer->setPhones(array($phonenumber));
                    }

                    // Emails: at least one email is required
                    // Groove only supports one email address, which means the email field could contain multiple emails
                    $emailAddresses = array();
                    $splitEmails = preg_split("/( |;|,)/", $grooveCustomer['email']);
                    // test to make sure all email addresses are valid
                    if (sizeof($splitEmails) == 1) {
                        $emailEntry = new EmailEntry();
                        $emailEntry->setValue($grooveCustomer['email']);
                        $emailEntry->setLocation("primary");

                        array_push($emailAddresses, $emailEntry);
                    } else {
                        // Test to make sure every email address is valid
                        $first = true;
                        foreach ($splitEmails as $addressToTest) {
                            if (strlen(trim($addressToTest)) === 0) {
                                continue;
                            }
                            if (!filter_var($addressToTest, FILTER_VALIDATE_EMAIL)) {
                                // breaking up the address resulted in invalid emails; use the original address
                                $emailAddresses = array();
                                $emailEntry = new EmailEntry();
                                $emailEntry->setValue($grooveCustomer['email']);
                                $emailEntry->setLocation("primary");

                                array_push($emailAddresses, $emailEntry);

                                break;
                            } else {
                                $emailEntry = new EmailEntry();
                                $emailEntry->setValue($addressToTest);

                                if ($first) {
                                    $emailEntry->setLocation("primary");
                                    $first = false;
                                } else {
                                    $emailEntry->setLocation("other");
                                }

                                array_push($emailAddresses, $emailEntry);
                            }
                        }
                    }
                    $customer->setEmails($emailAddresses);

                    // Social Profiles (Groove supports Twitter and LinkedIn)
                    $socialProfiles = array();
                    if ($grooveCustomer['twitter_username'] != null) {
                        $twitter = new SocialProfileEntry();
                        $twitter->setValue($grooveCustomer['twitter_username']);
                        $twitter->setType("twitter");
                        $socialProfiles [] = $twitter;
                    }

                    if ($grooveCustomer['linkedin_username'] != null) {
                        $linkedin = new SocialProfileEntry();
                        $linkedin->setValue($grooveCustomer['linkedin_username']);
                        $linkedin->setType("linkedin");
                        $socialProfiles [] = $linkedin;
                    }

                    $customer->setSocialProfiles($socialProfiles);

                    // Groove doesn't have chats

                    if ($grooveCustomer['website_url'] != null) {
                        $website = new WebsiteEntry();
                        $website->setValue($grooveCustomer['website_url']);

                        $customer->setWebsites(array($website));
                    }

                    $processedCustomers [] = $customer;
                } catch (ApiException $e) {
                    $grooveCustomerName = $grooveCustomer['name'];
                    $consoleCommand->error("Failed to create HelpScout customer for Groove customer \"$grooveCustomerName\" ($grooveCustomerEmail). Message was: " . APIHelper::formatApiExceptionArray($e));
                }
            }
            return $processedCustomers;
        };
    }
}