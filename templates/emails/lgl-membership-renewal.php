<?php
/**
 * Membership Renewal Email Template
 * 
 * This template can be styled by Kadence WooCommerce Email Designer.
 * 
 * @var string $email_heading Email heading
 * @var array $email_data Email data (first_name, last_name, days_until_renewal, membership_level, renewal_date)
 * @var bool $sent_to_admin Whether email is sent to admin
 * @var bool $plain_text Whether email is plain text
 * @var WC_Email $email Email object
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action('woocommerce_email_header', $email_heading, $email);

// Get content based on days until renewal
$days_until_renewal = $email_data['days_until_renewal'] ?? 0;
$first_name = $email_data['first_name'] ?? 'Member';
$last_name = $email_data['last_name'] ?? '';
$membership_level = $email_data['membership_level'] ?? '';
$renewal_date = $email_data['renewal_date'] ?? '';
$site_url = get_site_url();

// Get content from settings or use fallback
$content = '';
$settings_manager = null;

// Try to get settings manager from email object if available
if (method_exists($email, 'getSettingsManager')) {
    $settings_manager = $email->getSettingsManager();
}

if ($settings_manager) {
    $settings = $settings_manager->getAll();
    // Map days to interval
    $intervals = [30, 14, 7, 0, -7, -30];
    $closest = $intervals[0];
    $smallest_diff = abs($days_until_renewal - $closest);
    foreach ($intervals as $interval) {
        $diff = abs($days_until_renewal - $interval);
        if ($diff < $smallest_diff) {
            $closest = $interval;
            $smallest_diff = $diff;
        }
    }
    $interval_key = (string) $closest;
    $template_content = $settings["renewal_email_content_{$interval_key}"] ?? '';
    
    if (!empty($template_content)) {
        $content = str_replace(
            ['{first_name}', '{last_name}', '{renewal_date}', '{days_until_renewal}', '{membership_level}'],
            [$first_name, $last_name, $renewal_date, $days_until_renewal, $membership_level],
            $template_content
        );
    }
}

// Fallback to hardcoded content if no settings content
if (empty($content)) {
    if ($days_until_renewal === -30) {
        $content = '<h1>There\'s an issue with your membership subscription.</h1>
            <h2>Your membership renewal date has passed and your one month grace period to renew your membership has expired.</h2>
            <p><b>Your membership account has been marked as inactive.</b></p>
            <p>If your membership plan includes family members, their accounts have also been marked as inactive.</p>
            <p>After a 60 day period of inactivity, all user data for your account and family members\' accounts will be permanently removed from the Upstate International website.</p>
            <h3>To reactivate your account</h3>
            <p>Please follow the following steps:</p>
            <ol>
                <li>Reset your account password using the <a href="' . $site_url . '/my-account/lost-password">Login & Reset Password form</a>.</li>
                <li>Make a new password and login into your account.</li>
                <li>Add a Membership Level to your cart & complete your online checkout</li>
            </ol>
            <p>If you need to make changes to your membership, please feel free to stop by the office or contact us at:</p>
            <ul>
                <li><a href="tel:+18646312188">864-631-2188</a></li>
                <li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
            </ul>';
    } elseif ($days_until_renewal < 0 && $days_until_renewal >= -29) {
        $content = '<h1>Please renew your membership - it means the World to UI!</h1>
            <h2>Your membership renewal date has passed.</h2>
            <p>Please login to your <a href="' . $site_url . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but completing an online order is the most convenient to retain your Upstate International subscription.</strong></p>
            <p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p>
            <p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
            <ul>
                <li><a href="tel:+18646312188">864-631-2188</a></li>
                <li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
            </ul>';
    } elseif ($days_until_renewal === 0) {
        $content = '<h1>Today is the day!</h1>
            <h2>Your Upstate International Membership renewal date is today.</h2>
            <p>Please login to your <a href="' . $site_url . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but completing an online order is the most convenient to retain your Upstate International subscription.</strong></p>
            <p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p>
            <p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
            <ul>
                <li><a href="tel:+18646312188">864-631-2188</a></li>
                <li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
            </ul>';
    } elseif ($days_until_renewal === 7) {
        $content = '<h1>One more week!</h1>
            <h2>Your Upstate International Membership renewal date is in 7 days.</h2>
            <p>Please login to your <a href="' . $site_url . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but completing an online order is the most convenient to retain your Upstate International subscription.</strong></p>
            <p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p>
            <p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
            <ul>
                <li><a href="tel:+18646312188">864-631-2188</a></li>
                <li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
            </ul>';
    } elseif ($days_until_renewal === 14) {
        $content = '<h1>Two more weeks!</h1>
            <h2>Your Upstate International Membership renewal date is in 14 days.</h2>
            <p>Please login to your <a href="' . $site_url . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online.</p>
            <p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
            <ul>
                <li><a href="tel:+18646312188">864-631-2188</a></li>
                <li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
            </ul>';
    } elseif ($days_until_renewal === 30) {
        $content = '<h1>One more month!</h1>
            <h2>Your Upstate International Membership renewal date is in 30 days.</h2>
            <p>Please login to your <a href="' . $site_url . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online.</p>
            <p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
            <ul>
                <li><a href="tel:+18646312188">864-631-2188</a></li>
                <li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
            </ul>';
    } else {
        $days_text = abs($days_until_renewal) . ' days';
        if ($days_until_renewal < 0) {
            $days_text .= ' ago';
        }
        $content = '<h1>Membership Renewal Reminder</h1>
            <h2>Your Upstate International Membership renewal date was ' . $days_text . '.</h2>
            <p>Please login to your <a href="' . $site_url . '/my-account/">Upstate International online account</a> to renew your membership.</p>
            <p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
            <ul>
                <li><a href="tel:+18646312188">864-631-2188</a></li>
                <li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
            </ul>';
    }
}

echo wp_kses_post(wpautop(wptexturize($content)));

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_email_footer', $email);

