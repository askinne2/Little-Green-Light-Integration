<?php
/**
 * Family Member Welcome Email Template (Plain Text)
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

$first_name = $email_data['first_name'] ?? 'Member';
$password_reset_url = $email_data['password_reset_url'] ?? '';

echo "Hi " . $first_name . ",\n\n";

echo "Welcome to the Upstate International Family!\n\n";

echo "You've been added as a family member!\n\n";

echo "A family account holder has added you to their Upstate International membership. This means you now have full access to all the benefits and programs that Upstate International has to offer!\n\n";

echo "SET YOUR PASSWORD\n";
echo str_repeat("=", 50) . "\n\n";

echo "To access your account and begin enjoying your membership benefits, please visit the following URL to set your password:\n\n";

echo $password_reset_url . "\n\n";

echo "MEMBERSHIP BENEFITS\n";
echo str_repeat("=", 50) . "\n\n";

echo "As a family member, you can enjoy everything Upstate International has to offer, including:\n";
echo "- Language Classes\n";
echo "- Upstate International Month\n";
echo "- Salsa at Sunset\n";
echo "- World Affairs Council Upstate America and the World Lecture Series\n";
echo "- International Women's Group (IWG)\n";
echo "- Upstate International Book Club\n";
echo "- Retired ExPat's Group\n";
echo "- Conversation Clubs\n";
echo "- Business Networking Events\n";
echo "- Religious Traditions Series\n";
echo "- Glühwein Party\n";
echo "- Crepe Day\n";
echo "- Beaujolais Nouveau Dinner\n";
echo "- King's Cake Celebration\n";
echo "- Cultural Celebrations\n";
echo "- and so much more...\n\n";

echo "Welcome to the family!\n\n";

echo "South Carolina, Including the World, Begins with U&I.\n\n";

echo "Contact us:\n";
echo "Phone: 864-631-2188\n";
echo "Email: info@upstateinternational.org\n";

