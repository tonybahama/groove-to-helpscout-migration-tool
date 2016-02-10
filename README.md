# groove-to-helpscout-migration-tool

**Work-in-progress**. ETL project to migrate from GrooveHQ to HelpScout via APIs.

This ETL tool uses the Acquire -> Process -> Publish sequence of phases as suggested by http://www.seabourneinc.com/rethinking-etl-for-the-api-age/

## Requirements

- PHP 5.4+
- `allow_url_fopen` must be allowed (for attachment downloads)
- [Laravel](https://laravel.com/docs/5.1/installation) (for queues)
- MySQL (for maintaining queued jobs) - can be installed via [MAMP](https://www.mamp.info/en/)
- [Composer](https://getcomposer.org/download/)

### Dependencies

We leverage the following libraries via Composer:
- [helpscout/api](https://github.com/helpscout/helpscout-api-php)
- [jadb/php-groovehq](https://github.com/jadb/php-groovehq)

## Usage

Clone project and run `composer install` in the root folder of this project.

Update config/services.php to use your API keys and your default HelpScout mailbox.

Ensure mailbox names within HelpScout correspond to the same mailbox names as Groove. A check will be made before syncing tickets.

### Within HelpScout

Create all of your agent (team, user & mailbox) accounts in HelpScout first. Our tool will need to map the Groove agent 
email addresses with HelpScout user emails and Groove mailboxes to HelpScout mailboxes (manual input may be required).

### CLI Usage

Run `php artisan sync-customers` in the root of the Laravel project. Customers come first, as the process of creating conversations may create a new customer.
Once that succeeds, run: `php artisan sync-tickets` and `php artisan sync-attachments`.

## Notes

This tool is compatible with V1 of both Groove and HelpScout APIs.

As with all content management systems: Garbage in, garbage out.

If your customer's full name is their phone number, do not expect the first and last name in HelpScout to make any
sense. If the email is invalid, you will likely need to manually create these users yourself.

### What is migrated
- Customers
- Tickets and messages
- Attachments and images

### Mapping limitations

Please be aware of the following when importing:
- Groove stores full names of customers instead of first and last name
- Groove does not maintain customer addresses
- Groove only supports a single email address field (we will do our best to parse multiple email addresses)
- Groove mainly supports Twitter and LinkedIn as social media platforms
- Groove doesn't have chat accounts out-of-the-box
- HelpScout API does not support creation of team members (agents); team members and mailboxes will have to be manually created
- HelpScout API doesn't appear to have any concept of priorities for conversations/threads

For help moving from Zendesk, Desk or UserVoice, check out the [HelpScout knowledge base](http://docs.helpscout.net/category/74-copying-email-to-help-scout).

## TODOs

The tool currently does not perform:

- Restarting only failed migration tasks
- Export CSV of imports with issues

## Challenges

Long-running process (may take hours; batches may fail anytime).
Queueing several jobs while adhering to API rate limit. 
Monitoring on the front-end. 
Start the job then how do you tell what has been finished, and which ones failed?