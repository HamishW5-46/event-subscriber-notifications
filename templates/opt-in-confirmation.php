<?php
/**
 * Opt-in Confirmation Email Template
 *
 * Available variables:
 *  - $confirm_url : string
 *  - $site_name   : string
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Confirm Your Subscription</title>
<style>
    body { font-family: Arial, sans-serif; color: #333; line-height: 1.5; }
    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
    .btn { display: inline-block; background: #0073aa; color: #fff; padding: 12px 20px; text-decoration: none; border-radius: 4px; margin-top: 20px; }
    .footer { margin-top: 30px; font-size: 12px; color: #666; }
</style>
</head>
<body>
<div class="container">
    <h2>Please confirm you would like to receive notifications of new events published by <?php echo esc_html($site_name); ?></h2>
    <p>Thanks for subscribing to event notifications! Please confirm your subscription by clicking the button below:</p>
    <p><a class="btn" href="<?php echo esc_url($confirm_url); ?>">Confirm Subscription</a></p>
    <p class="footer">
        If you did not request this subscription, you can safely ignore this email.
    </p>
</div>
</body>
</html>
