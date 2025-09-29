<?php
/**
 * Test Autoloader in WordPress Environment
 */

// Load the autoloader
require_once __DIR__ . '/vendor/autoload.php';

echo "Testing autoloader...\n";

// Test if the classes can be found
$classes = [
    'UpstateInternational\LGL\Memberships\MembershipNotificationMailer',
    'UpstateInternational\LGL\Memberships\MembershipUserManager',
    'UpstateInternational\LGL\Memberships\MembershipRenewalManager',
    'UpstateInternational\LGL\Memberships\MembershipCronManager'
];

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "✅ {$class} - FOUND\n";
    } else {
        echo "❌ {$class} - NOT FOUND\n";
    }
}

echo "\nAutoloader test complete.\n";
