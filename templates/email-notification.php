<?php
/**
 * Notification Email Template
 *
 * Available variables:
 *  - $post_title : string
 *  - $post_url   : string
 *  - $post_excerpt : string
 *  - $unsubscribe : string
 *  - $site_name   : string
 *  - $site_url    : string
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>New Post Published</title>
<style>
    body { font-family: Arial, sans-serif; color: #333; line-height: 1.5; }
    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
    .btn { display: inline-block; background: #0073aa; color: #fff; padding: 12px 20px; text-decoration: none; border-radius: 4px; margin-top: 20px; }
    .footer { margin-top: 30px; font-size: 12px; color: #666; }
</style>
</head>
<body>
<div class="container">
    <h2>New Post: <?php echo esc_html($post_title); ?></h2>
    <p>We just published a new post. You can view it here:</p>
    <?php if ( ! empty( $post_excerpt ) ) : ?>
        <p><?php echo esc_html( $post_excerpt ); ?></p>
    <?php endif; ?>
    <p><a class="btn" href="<?php echo esc_url($post_url); ?>">View Post</a></p>
    <p class="footer">
        You are receiving this email because you subscribed to notifications from <a href="<?php echo esc_url($site_url); ?>"><?php echo esc_html($site_name); ?></a>. If you no longer wish to receive these emails, you can <a href="<?php echo esc_url($unsubscribe); ?>">unsubscribe here</a>.
    </p>
</div>
</body>
</html>
