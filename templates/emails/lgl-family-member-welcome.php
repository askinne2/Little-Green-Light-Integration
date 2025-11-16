<?php
/**
 * Family Member Welcome Email Template
 * 
 * This template can be styled by Kadence WooCommerce Email Designer.
 * 
 * @var string $email_heading Email heading
 * @var array $email_data Email data (first_name, password_reset_url, site_url)
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

$first_name = $email_data['first_name'] ?? 'Member';
$password_reset_url = $email_data['password_reset_url'] ?? '';
$site_url = $email_data['site_url'] ?? home_url();

?>

<p><?php printf(esc_html__('Hi %s,', 'woocommerce'), esc_html($first_name)); ?></p>

<h2><?php esc_html_e('Welcome to the Upstate International Family!', 'woocommerce'); ?></h2>

<p><strong><?php esc_html_e('You\'ve been added as a family member!', 'woocommerce'); ?></strong></p>

<p><?php esc_html_e('A family account holder has added you to their Upstate International membership. This means you now have full access to all the benefits and programs that Upstate International has to offer!', 'woocommerce'); ?></p>

<h3><?php esc_html_e('Set Your Password', 'woocommerce'); ?></h3>

<p><?php esc_html_e('To access your account and begin enjoying your membership benefits, please click the button below to set your password:', 'woocommerce'); ?></p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url($password_reset_url); ?>" style="display: inline-block; padding: 15px 30px; background-color: #0073aa; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 16px;">
        <?php esc_html_e('Set My Password', 'woocommerce'); ?>
    </a>
</p>

<p style="font-size: 11px; color: #666;">
    <?php esc_html_e('If the button doesn\'t work, copy and paste this URL into your browser:', 'woocommerce'); ?><br>
    <a href="<?php echo esc_url($password_reset_url); ?>" style="color: #0073aa;"><?php echo esc_url($password_reset_url); ?></a>
</p>

<hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;" />

<h3><?php esc_html_e('Your Membership Benefits', 'woocommerce'); ?></h3>

<p><?php esc_html_e('As a family member, you can enjoy everything Upstate International has to offer, including:', 'woocommerce'); ?></p>

<ul>
    <li><?php esc_html_e('Language Classes', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Upstate International Month', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Salsa at Sunset', 'woocommerce'); ?></li>
    <li><?php esc_html_e('World Affairs Council Upstate America and the World Lecture Series', 'woocommerce'); ?></li>
    <li><?php esc_html_e('International Women\'s Group (IWG)', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Upstate International Book Club', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Retired ExPat\'s Group', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Conversation Clubs', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Business Networking Events', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Religious Traditions Series', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Glühwein Party', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Crepe Day', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Beaujolais Nouveau Dinner', 'woocommerce'); ?></li>
    <li><?php esc_html_e('King\'s Cake Celebration', 'woocommerce'); ?></li>
    <li><?php esc_html_e('Cultural Celebrations', 'woocommerce'); ?></li>
    <li><?php esc_html_e('and so much more...', 'woocommerce'); ?></li>
</ul>

<p><?php esc_html_e('Honestly, though, it is the intangibles, the quiet acceptance, the warm smile, the unexpected friendships that make your membership in this organization truly unique and worthwhile. We are a membership, a family, and we are here for you!', 'woocommerce'); ?></p>

<h3><?php esc_html_e('Welcome to the family!', 'woocommerce'); ?></h3>

<p><strong><?php esc_html_e('South Carolina, Including the World, Begins with U&I.', 'woocommerce'); ?></strong></p>

<p><?php esc_html_e('Please feel free to stop by the office or contact us using the information below!', 'woocommerce'); ?></p>

<p>
    <?php esc_html_e('Phone:', 'woocommerce'); ?> <a href="tel:+18646312188">864-631-2188</a><br>
    <?php esc_html_e('Email:', 'woocommerce'); ?> <a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a>
</p>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_email_footer', $email);

