<?php
/**
 * Membership Renewal Email Template (Plain Text)
 * 
 * @var string $email_heading Email heading
 * @var array $email_data Email data
 * @var bool $sent_to_admin Whether email is sent to admin
 * @var bool $plain_text Whether email is plain text
 * @var WC_Email $email Email object
 */

if (!defined('ABSPATH')) {
    exit;
}

echo "= " . $email_heading . " =\n\n";

$days_until_renewal = $email_data['days_until_renewal'] ?? 0;
$first_name = $email_data['first_name'] ?? 'Member';
$site_url = get_site_url();

if ($days_until_renewal === -30) {
    echo "There's an issue with your membership subscription.\n\n";
    echo "Your membership renewal date has passed and your one month grace period to renew your membership has expired.\n\n";
    echo "Your membership account has been marked as inactive.\n\n";
    echo "To reactivate your account, please visit: " . $site_url . "/my-account/lost-password\n\n";
} elseif ($days_until_renewal < 0) {
    echo "Please renew your membership - it means the World to UI!\n\n";
    echo "Your membership renewal date has passed.\n\n";
    echo "Please login to your account: " . $site_url . "/my-account/\n\n";
} elseif ($days_until_renewal === 0) {
    echo "Today is the day!\n\n";
    echo "Your Upstate International Membership renewal date is today.\n\n";
    echo "Please login to your account: " . $site_url . "/my-account/\n\n";
} elseif ($days_until_renewal === 7) {
    echo "One more week!\n\n";
    echo "Your Upstate International Membership renewal date is in 7 days.\n\n";
    echo "Please login to your account: " . $site_url . "/my-account/\n\n";
} elseif ($days_until_renewal === 14) {
    echo "Two more weeks!\n\n";
    echo "Your Upstate International Membership renewal date is in 14 days.\n\n";
    echo "Please login to your account: " . $site_url . "/my-account/\n\n";
} elseif ($days_until_renewal === 30) {
    echo "One more month!\n\n";
    echo "Your Upstate International Membership renewal date is in 30 days.\n\n";
    echo "Please login to your account: " . $site_url . "/my-account/\n\n";
} else {
    echo "Membership Renewal Reminder\n\n";
    echo "Your Upstate International Membership renewal date is approaching.\n\n";
    echo "Please login to your account: " . $site_url . "/my-account/\n\n";
}

echo "\n---\n";
echo "Contact us:\n";
echo "Phone: 864-631-2188\n";
echo "Email: info@upstateinternational.org\n";

