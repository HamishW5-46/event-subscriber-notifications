<?php
/**
 * Plugin Name: Event Notifications - Opt In & Subscription
 * Description: Automatically emails subscribers when new Events Calendar events are published.
 * Version: 1.2.0
 * Author: Hamish W
 * License: GPL2+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ESN_TOKEN_LENGTH       = 64;
const ESN_SENT_META          = '_esn_notification_sent';
const ESN_CRON_HOOK          = 'esn_send_event_notifications';
const ESN_BATCH_OFFSET_META  = '_esn_notification_offset';
const ESN_OPTION_KEY         = 'esn_settings';
const ESN_REWRITE_FLUSH_FLAG = 'esn_flush_rewrite_rules';
const ESN_TABLE_VERSION      = '1.0';

require_once __DIR__ . '/event-template.php';

function esn_get_default_settings(): array {
	return [
		'subscribe_slug'        => 'subscribe',
		'subscriptions_base'    => 'subscriptions',
		'confirm_slug'          => 'confirm',
		'unsubscribe_slug'      => 'unsubscribe',
		'batch_size'            => 50,
		'send_delay'            => 60,
		'notification_subject'  => 'New Event Posted: {event_title}',
		'confirmation_subject'  => 'Opt in to event notifications',
		'from_name'             => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'from_email'            => get_option( 'admin_email' ),
		'show_dashboard_widget' => 1,
	];
}

function esn_get_settings(): array {
	return wp_parse_args( get_option( ESN_OPTION_KEY, [] ), esn_get_default_settings() );
}

function esn_get_setting( string $key ) {
	$settings = esn_get_settings();
	return $settings[ $key ] ?? null;
}

function esn_get_batch_size(): int {
	return max( 1, (int) esn_get_setting( 'batch_size' ) );
}

function esn_get_send_delay(): int {
	return max( 10, (int) esn_get_setting( 'send_delay' ) );
}

function esn_get_subscribe_slug(): string {
	return sanitize_title( (string) esn_get_setting( 'subscribe_slug' ) );
}

function esn_get_subscriptions_base(): string {
	return sanitize_title( (string) esn_get_setting( 'subscriptions_base' ) );
}

function esn_get_confirm_slug(): string {
	return sanitize_title( (string) esn_get_setting( 'confirm_slug' ) );
}

function esn_get_unsubscribe_slug(): string {
	return sanitize_title( (string) esn_get_setting( 'unsubscribe_slug' ) );
}

function esn_get_subscribe_url(): string {
	return home_url( '/' . esn_get_subscribe_slug() . '/' );
}

function esn_get_token_url( string $action, string $token ): string {
	$base = esn_get_subscriptions_base();
	$slug = 'confirm' === $action ? esn_get_confirm_slug() : esn_get_unsubscribe_slug();
	return home_url( '/' . $base . '/' . $slug . '/' . rawurlencode( $token ) . '/' );
}

function esn_get_subscribers_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'esn_subscribers';
}

function esn_install_table(): void {
	global $wpdb;

	$table_name      = esn_get_subscribers_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email varchar(190) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		token varchar(64) NOT NULL,
		source varchar(100) NOT NULL DEFAULT 'website',
		created_at datetime NOT NULL,
		confirmed_at datetime NULL DEFAULT NULL,
		unsubscribed_at datetime NULL DEFAULT NULL,
		last_sent_at datetime NULL DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email),
		UNIQUE KEY token (token),
		KEY status (status)
	) {$charset_collate};";

	dbDelta( $sql );
	update_option( 'esn_table_version', ESN_TABLE_VERSION );
}

function esn_register_rewrite_rules(): void {
	add_rewrite_rule(
		'^' . preg_quote( esn_get_subscriptions_base(), '/' ) . '/' . preg_quote( esn_get_confirm_slug(), '/' ) . '/([A-Za-z0-9]{64})/?$',
		'index.php?esn_action=confirm&token=$matches[1]',
		'top'
	);

	add_rewrite_rule(
		'^' . preg_quote( esn_get_subscriptions_base(), '/' ) . '/' . preg_quote( esn_get_unsubscribe_slug(), '/' ) . '/([A-Za-z0-9]{64})/?$',
		'index.php?esn_action=unsubscribe&token=$matches[1]',
		'top'
	);

	add_rewrite_rule(
		'^' . preg_quote( esn_get_subscribe_slug(), '/' ) . '/?$',
		'index.php?esn_virtual=subscribe',
		'top'
	);
}

function esn_activate_plugin(): void {
	esn_install_table();
	esn_register_rewrite_rules();
	flush_rewrite_rules();
}

function esn_deactivate_plugin(): void {
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'esn_activate_plugin' );
register_deactivation_hook( __FILE__, 'esn_deactivate_plugin' );

function esn_maybe_install_table(): void {
	if ( ESN_TABLE_VERSION !== get_option( 'esn_table_version' ) ) {
		esn_install_table();
	}
}

add_action( 'plugins_loaded', 'esn_maybe_install_table' );

add_action( 'init', 'esn_register_rewrite_rules' );

add_filter(
	'query_vars',
	static function ( array $vars ): array {
		$vars[] = 'esn_action';
		$vars[] = 'token';
		$vars[] = 'esn_virtual';
		return $vars;
	}
);

function esn_maybe_flush_rewrite_rules(): void {
	if ( get_option( ESN_REWRITE_FLUSH_FLAG ) ) {
		flush_rewrite_rules( false );
		delete_option( ESN_REWRITE_FLUSH_FLAG );
	}
}

add_action( 'init', 'esn_maybe_flush_rewrite_rules', 20 );

function esn_generate_token(): string {
	return wp_generate_password( ESN_TOKEN_LENGTH, false, false );
}

function esn_render_email_template( string $template, array $vars = [] ): string {
	$path = __DIR__ . '/templates/' . $template . '.php';
	if ( ! file_exists( $path ) ) {
		return '';
	}

	extract( $vars, EXTR_SKIP );
	ob_start();
	include $path;
	return (string) ob_get_clean();
}

function esn_send_html_mail( string $to, string $subject, string $message ): bool {
	$settings   = esn_get_settings();
	$from_name  = sanitize_text_field( (string) $settings['from_name'] );
	$from_email = sanitize_email( (string) $settings['from_email'] );

	$content_type_filter = static function (): string {
		return 'text/html';
	};

	$from_name_filter = static function () use ( $from_name ): string {
		return $from_name;
	};

	$from_email_filter = static function () use ( $from_email ): string {
		return $from_email;
	};

	add_filter( 'wp_mail_content_type', $content_type_filter );

	if ( $from_name ) {
		add_filter( 'wp_mail_from_name', $from_name_filter );
	}

	if ( $from_email ) {
		add_filter( 'wp_mail_from', $from_email_filter );
	}

	$sent = wp_mail( $to, $subject, $message );

	remove_filter( 'wp_mail_content_type', $content_type_filter );

	if ( $from_name ) {
		remove_filter( 'wp_mail_from_name', $from_name_filter );
	}

	if ( $from_email ) {
		remove_filter( 'wp_mail_from', $from_email_filter );
	}

	return (bool) $sent;
}

function esn_get_subscriber_by_email( string $email ): ?array {
	global $wpdb;

	$table_name = esn_get_subscribers_table_name();
	$row        = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE email = %s LIMIT 1",
			$email
		),
		ARRAY_A
	);

	return $row ?: null;
}

function esn_get_subscriber_by_token( string $token ): ?array {
	global $wpdb;

	$table_name = esn_get_subscribers_table_name();
	$row        = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE token = %s LIMIT 1",
			$token
		),
		ARRAY_A
	);

	return $row ?: null;
}

function esn_insert_subscriber( string $email, string $status = 'pending', string $source = 'website' ): ?array {
	global $wpdb;

	$table_name = esn_get_subscribers_table_name();
	$token      = esn_generate_token();
	$created_at = current_time( 'mysql' );

	$inserted = $wpdb->insert(
		$table_name,
		[
			'email'      => $email,
			'status'     => $status,
			'token'      => $token,
			'source'     => $source,
			'created_at' => $created_at,
		],
		[
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		]
	);

	if ( false === $inserted ) {
		return null;
	}

	return esn_get_subscriber_by_email( $email );
}

function esn_update_subscriber( int $subscriber_id, array $data ): bool {
	global $wpdb;

	if ( empty( $data ) ) {
		return true;
	}

	$table_name = esn_get_subscribers_table_name();
	$formats    = [];

	foreach ( array_keys( $data ) as $key ) {
		$formats[] = 'id' === $key ? '%d' : '%s';
	}

	$updated = $wpdb->update(
		$table_name,
		$data,
		[ 'id' => $subscriber_id ],
		$formats,
		[ '%d' ]
	);

	return false !== $updated;
}

function esn_get_subscriber_counts(): array {
	global $wpdb;

	$table_name = esn_get_subscribers_table_name();
	$rows       = $wpdb->get_results(
		"SELECT status, COUNT(*) AS total FROM {$table_name} GROUP BY status",
		ARRAY_A
	);

	$counts = [
		'pending'      => 0,
		'subscribed'   => 0,
		'unsubscribed' => 0,
	];

	foreach ( $rows as $row ) {
		if ( isset( $counts[ $row['status'] ] ) ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
	}

	return $counts;
}

function esn_get_subscribers( int $per_page = 50, int $offset = 0, string $search = '' ): array {
	global $wpdb;

	$table_name = esn_get_subscribers_table_name();
	$where_sql  = '';
	$params     = [];

	if ( '' !== $search ) {
		$where_sql = 'WHERE email LIKE %s';
		$params[]  = '%' . $wpdb->esc_like( $search ) . '%';
	}

	$params[] = $per_page;
	$params[] = $offset;

	$query = "SELECT * FROM {$table_name} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";

	return $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );
}

function esn_count_all_subscribers( string $search = '' ): int {
	global $wpdb;

	$table_name = esn_get_subscribers_table_name();

	if ( '' === $search ) {
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE email LIKE %s",
			'%' . $wpdb->esc_like( $search ) . '%'
		)
	);
}

function esn_delete_subscriber( int $subscriber_id ): bool {
	global $wpdb;

	return false !== $wpdb->delete( esn_get_subscribers_table_name(), [ 'id' => $subscriber_id ], [ '%d' ] );
}

function esn_send_confirmation_email( array $subscriber ): bool {
	$message = esn_render_email_template(
		'opt-in-confirmation',
		[
			'confirm_url' => esn_get_token_url( 'confirm', $subscriber['token'] ),
			'site_name'   => get_bloginfo( 'name' ),
		]
	);

	return esn_send_html_mail(
		$subscriber['email'],
		(string) esn_get_setting( 'confirmation_subject' ),
		$message
	);
}

function esn_send_event_email( array $subscriber, int $event_id ): bool {
	global $wpdb;

	$event_title   = html_entity_decode( get_the_title( $event_id ), ENT_QUOTES | ENT_HTML5 );
	$event_url     = get_permalink( $event_id );
	$event_post    = get_post( $event_id );
	$event_excerpt = '';

	if ( $event_post instanceof WP_Post ) {
		$event_excerpt = has_excerpt( $event_id )
			? $event_post->post_excerpt
			: wp_trim_words( wp_strip_all_tags( (string) $event_post->post_content ), 40 );
	}

	$message = esn_render_email_template(
		'email-notification',
		[
			'event_title'   => $event_title,
			'event_url'     => $event_url,
			'event_excerpt' => $event_excerpt,
			'unsubscribe'   => esn_get_token_url( 'unsubscribe', $subscriber['token'] ),
			'site_name'     => get_bloginfo( 'name' ),
			'site_url'      => home_url( '/' ),
		]
	);

	$sent = esn_send_html_mail(
		$subscriber['email'],
		str_replace( '{event_title}', $event_title, (string) esn_get_setting( 'notification_subject' ) ),
		$message
	);

	if ( $sent ) {
		$wpdb->update(
			esn_get_subscribers_table_name(),
			[ 'last_sent_at' => current_time( 'mysql' ) ],
			[ 'id' => (int) $subscriber['id'] ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	return $sent;
}

function esn_subscribe_email( string $email, string $source = 'website' ): array {
	$email = sanitize_email( $email );

	if ( ! is_email( $email ) ) {
		return [
			'success' => false,
			'message' => 'Please enter a valid email address.',
		];
	}

	$subscriber = esn_get_subscriber_by_email( $email );
	$was_pending = $subscriber && 'pending' === $subscriber['status'];

	if ( $subscriber && 'subscribed' === $subscriber['status'] ) {
		return [
			'success' => true,
			'message' => 'This email is already subscribed to event notifications.',
		];
	}

	if ( ! $subscriber ) {
		$subscriber = esn_insert_subscriber( $email, 'pending', $source );
		if ( ! $subscriber ) {
			return [
				'success' => false,
				'message' => 'We could not save this subscription. Please try again.',
			];
		}
	} else {
		$updated = esn_update_subscriber(
			(int) $subscriber['id'],
			[
				'status'          => 'pending',
				'token'           => esn_generate_token(),
				'source'          => $source,
				'unsubscribed_at' => null,
			]
		);

		if ( ! $updated ) {
			return [
				'success' => false,
				'message' => 'We could not update this subscription. Please try again.',
			];
		}

		$subscriber = esn_get_subscriber_by_email( $email );
	}

	if ( ! esn_send_confirmation_email( $subscriber ) ) {
		return [
			'success' => false,
			'message' => 'We could not send the confirmation email. Please try again.',
		];
	}

	return [
		'success' => true,
		'message' => $was_pending
			? 'A fresh confirmation email has been sent. Please check your inbox.'
			: 'Thanks! Check your inbox to confirm your subscription.',
	];
}

function esn_schedule_event_notification( string $new_status, string $old_status, WP_Post $post ): void {
	if ( 'tribe_events' !== $post->post_type ) {
		return;
	}

	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	if ( get_post_meta( $post->ID, ESN_SENT_META, true ) ) {
		return;
	}

	if ( wp_next_scheduled( ESN_CRON_HOOK, [ $post->ID ] ) ) {
		return;
	}

	delete_post_meta( $post->ID, ESN_BATCH_OFFSET_META );
	wp_schedule_single_event( time() + esn_get_send_delay(), ESN_CRON_HOOK, [ $post->ID ] );
}

add_action( 'transition_post_status', 'esn_schedule_event_notification', 10, 3 );

function esn_send_event_notifications_batch( int $event_id ): void {
	global $wpdb;

	if ( get_post_meta( $event_id, ESN_SENT_META, true ) ) {
		return;
	}

	$batch_size  = esn_get_batch_size();
	$offset      = max( 0, (int) get_post_meta( $event_id, ESN_BATCH_OFFSET_META, true ) );
	$table_name  = esn_get_subscribers_table_name();
	$subscribers = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE status = %s ORDER BY id ASC LIMIT %d OFFSET %d",
			'subscribed',
			$batch_size,
			$offset
		),
		ARRAY_A
	);

	if ( empty( $subscribers ) ) {
		update_post_meta( $event_id, ESN_SENT_META, 1 );
		delete_post_meta( $event_id, ESN_BATCH_OFFSET_META );
		return;
	}

	foreach ( $subscribers as $subscriber ) {
		esn_send_event_email( $subscriber, $event_id );
	}

	if ( count( $subscribers ) < $batch_size ) {
		update_post_meta( $event_id, ESN_SENT_META, 1 );
		delete_post_meta( $event_id, ESN_BATCH_OFFSET_META );
		return;
	}

	update_post_meta( $event_id, ESN_BATCH_OFFSET_META, $offset + $batch_size );
	wp_schedule_single_event( time() + esn_get_send_delay(), ESN_CRON_HOOK, [ $event_id ] );
}

add_action( ESN_CRON_HOOK, 'esn_send_event_notifications_batch' );

function esn_render_message_page( string $title, string $message ): void {
	status_header( 200 );
	get_header();

	echo '<main class="container" style="max-width:600px;margin:80px auto;text-align:center;">';
	echo '<h1>' . esc_html( $title ) . '</h1>';
	echo '<p>' . esc_html( $message ) . '</p>';
	echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Return to site', 'event-subscriber-notifications' ) . '</a></p>';
	echo '</main>';

	get_footer();
	exit;
}

function esn_confirm_subscription_token( string $token ): void {
	$subscriber = esn_get_subscriber_by_token( $token );

	if ( ! $subscriber ) {
		wp_die( esc_html__( 'Invalid or expired confirmation link.', 'event-subscriber-notifications' ) );
	}

	esn_update_subscriber(
		(int) $subscriber['id'],
		[
			'status'       => 'subscribed',
			'confirmed_at' => current_time( 'mysql' ),
		]
	);

	esn_render_message_page(
		'Subscription Confirmed',
		'You will now receive event notifications.'
	);
}

function esn_handle_unsubscribe_token( string $token ): void {
	$subscriber = esn_get_subscriber_by_token( $token );

	if ( ! $subscriber ) {
		wp_die( esc_html__( 'Invalid or expired link.', 'event-subscriber-notifications' ) );
	}

	esn_update_subscriber(
		(int) $subscriber['id'],
		[
			'status'          => 'unsubscribed',
			'unsubscribed_at' => current_time( 'mysql' ),
		]
	);

	esn_render_message_page(
		'You Have Been Unsubscribed',
		'You will no longer receive event notifications.'
	);
}

add_action(
	'template_redirect',
	static function (): void {
		$action = get_query_var( 'esn_action' );
		$token  = sanitize_text_field( (string) get_query_var( 'token' ) );

		if ( ! $action || ! $token ) {
			return;
		}

		if ( 'confirm' === $action ) {
			esn_confirm_subscription_token( $token );
		}

		if ( 'unsubscribe' === $action ) {
			esn_handle_unsubscribe_token( $token );
		}
	}
);

function esn_handle_fullpage_optin_submission(): void {
	if (
		empty( $_POST['esn_fullpage_optin'] ) ||
		empty( $_POST['esn_email'] ) ||
		empty( $_POST['esn_fullpage_optin_nonce'] )
	) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['esn_fullpage_optin_nonce'] ) ), 'esn_fullpage_optin_action' ) ) {
		return;
	}

	$result = esn_subscribe_email( sanitize_email( wp_unslash( $_POST['esn_email'] ) ), 'fullpage-form' );

	if ( $result['success'] ) {
		set_query_var( 'esn_form_notice', $result['message'] );
	}
}

add_action( 'init', 'esn_handle_fullpage_optin_submission' );

function esn_render_fullpage_optin_form(): string {
	$notice = (string) get_query_var( 'esn_form_notice' );

	ob_start();
	?>
	<div class="esn-fullpage-optin">
		<?php if ( $notice ) : ?>
			<p class="esn-success"><?php echo esc_html( $notice ); ?></p>
		<?php endif; ?>
		<form method="post" class="esn-form">
			<input type="email" name="esn_email" required placeholder="Your email address">
			<input type="hidden" name="esn_fullpage_optin" value="1">
			<?php wp_nonce_field( 'esn_fullpage_optin_action', 'esn_fullpage_optin_nonce' ); ?>
			<button type="submit">Subscribe</button>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

add_action(
	'template_redirect',
	static function (): void {
		if ( 'subscribe' !== get_query_var( 'esn_virtual' ) ) {
			return;
		}

		status_header( 200 );
		get_header();
		echo '<main class="container">';
		echo '<div class="esn-subscribe-main">';
		echo '<section class="esn-subscribe-section">';
		echo '<h1>Subscribe for Event Notifications</h1>';
		echo '<p>Sign up to receive email notifications when new events are posted.</p>';
		echo esn_render_fullpage_optin_form();
		echo '</section>';
		echo '</div>';
		echo '</main>';
		get_footer();
		exit;
	}
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( 'subscribe' !== get_query_var( 'esn_virtual' ) ) {
			return;
		}

		wp_register_style( 'esn-fullpage-optin-style', false );
		wp_enqueue_style( 'esn-fullpage-optin-style' );
		wp_add_inline_style(
			'esn-fullpage-optin-style',
			'
			.esn-subscribe-main {
				width: 100%;
				display: flex;
				align-items: center;
				justify-content: center;
			}
			.esn-subscribe-section {
				background: #fff;
				padding: 2rem 2.5rem;
				border-radius: 10px;
				box-shadow: 0 2px 16px rgba(0,0,0,0.07);
				max-width: 400px;
				width: 100%;
				text-align: center;
			}
			.esn-subscribe-section h1 {
				margin-bottom: 1rem;
			}
			.esn-subscribe-section p {
				margin-bottom: 2rem;
				color: #555;
			}
			.esn-fullpage-optin .esn-form {
				display: flex;
				flex-direction: column;
				gap: 1rem;
				align-items: stretch;
			}
			.esn-fullpage-optin input[type="email"] {
				width: 100%;
				padding: 0.75rem;
				border-radius: 5px;
				border: 1px solid #ccc;
				font-size: 1rem;
			}
			.esn-fullpage-optin button {
				padding: 0.75rem 1.5rem;
				border-radius: 5px;
				background: #0073aa;
				color: #fff;
				border: none;
				font-weight: bold;
				cursor: pointer;
				font-size: 1rem;
				transition: background 0.2s;
			}
			.esn-fullpage-optin button:hover {
				background: #005177;
			}
			.esn-fullpage-optin .esn-success {
				padding: 1rem;
				border-left: 4px solid #0073aa;
				background: #e6f4fa;
				color: #0073aa;
				border-radius: 5px;
				margin-bottom: 1rem;
			}
			'
		);
	}
);

function esn_optin_form_shortcode(): string {
	$form_id     = wp_unique_id( 'esn-optin-' );
	$button_id   = $form_id . '-button';
	$email_id    = $form_id . '-email';
	$response_id = $form_id . '-response';
	$nonce_id    = $form_id . '-nonce';

	ob_start();
	?>
	<div class="esn-shortcode-form">
		<h5>Want to know when new News or Events are published?</h5>
		<div class="esn-flex-container" style="display:flex;flex-wrap:wrap;gap:15px;align-items:center;justify-content:center;">
			<div style="flex:1;min-width:250px;">
				<input type="email" id="<?php echo esc_attr( $email_id ); ?>" placeholder="Enter your email" required>
			</div>
			<div style="flex:0 1 auto;min-width:150px;">
				<a href="#" class="button" style="width:100%;height:40px;display:block;text-align:center;line-height:40px;padding:0 30px;" id="<?php echo esc_attr( $button_id ); ?>">
					<span>Subscribe</span>
				</a>
			</div>
		</div>

		<input type="hidden" id="<?php echo esc_attr( $nonce_id ); ?>" value="<?php echo esc_attr( wp_create_nonce( 'esn_optin_action' ) ); ?>">
		<p id="<?php echo esc_attr( $response_id ); ?>" style="display:none;margin-top:10px;font-size:0.9em;"></p>
	</div>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		const subButton = document.getElementById('<?php echo esc_js( $button_id ); ?>');
		const emailInput = document.getElementById('<?php echo esc_js( $email_id ); ?>');
		const responseMsg = document.getElementById('<?php echo esc_js( $response_id ); ?>');
		const nonceInput = document.getElementById('<?php echo esc_js( $nonce_id ); ?>');

		if (!subButton || !emailInput || !responseMsg) {
			return;
		}

		subButton.addEventListener('click', function(e) {
			e.preventDefault();

			const email = emailInput.value.trim();
			const nonce = nonceInput ? nonceInput.value : '';

			if (!email || !email.includes('@')) {
				responseMsg.textContent = 'Please enter a valid email address.';
				responseMsg.style.color = 'red';
				responseMsg.style.display = 'block';
				return;
			}

			subButton.classList.add('elementor-button-disabled');
			subButton.style.pointerEvents = 'none';
			subButton.textContent = 'Sending...';

			fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Accept': 'application/json' },
				body: new URLSearchParams({
					action: 'esn_optin',
					esn_email: email,
					esn_optin_nonce: nonce
				})
			})
			.then(response => response.json())
			.then(data => {
				responseMsg.textContent = data.data && data.data.message ? data.data.message : 'Could not subscribe. Try again.';
				responseMsg.style.color = data.success ? '#005177' : 'red';
				responseMsg.style.display = 'block';

				if (data.success) {
					emailInput.style.display = 'none';
					subButton.style.display = 'none';
					return;
				}

				subButton.classList.remove('elementor-button-disabled');
				subButton.style.pointerEvents = 'auto';
				subButton.textContent = 'Subscribe';
			})
			.catch(() => {
				responseMsg.textContent = 'Could not subscribe. Try again.';
				responseMsg.style.color = 'red';
				responseMsg.style.display = 'block';
				subButton.classList.remove('elementor-button-disabled');
				subButton.style.pointerEvents = 'auto';
				subButton.textContent = 'Subscribe';
			});
		});
	});
	</script>
	<?php
	return (string) ob_get_clean();
}

add_shortcode( 'esn_optin_form', 'esn_optin_form_shortcode' );

function esn_optin_ajax_handler(): void {
	if (
		empty( $_POST['esn_email'] ) ||
		empty( $_POST['esn_optin_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['esn_optin_nonce'] ) ), 'esn_optin_action' )
	) {
		wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page and try again.' ] );
	}

	$result = esn_subscribe_email( sanitize_email( wp_unslash( $_POST['esn_email'] ) ), 'shortcode-form' );

	if ( $result['success'] ) {
		wp_send_json_success( [ 'message' => $result['message'] ] );
	}

	wp_send_json_error( [ 'message' => $result['message'] ] );
}

add_action( 'wp_ajax_esn_optin', 'esn_optin_ajax_handler' );
add_action( 'wp_ajax_nopriv_esn_optin', 'esn_optin_ajax_handler' );

function esn_register_settings(): void {
	register_setting(
		'esn_settings_group',
		ESN_OPTION_KEY,
		[
			'type'              => 'array',
			'sanitize_callback' => 'esn_sanitize_settings',
			'default'           => esn_get_default_settings(),
		]
	);

	add_settings_section( 'esn_general_section', 'General Settings', '__return_false', 'esn-settings' );
	add_settings_section( 'esn_email_section', 'Email Settings', '__return_false', 'esn-settings' );

	$fields = [
		'subscribe_slug'        => [ 'label' => 'Subscribe Page Slug', 'section' => 'esn_general_section' ],
		'subscriptions_base'    => [ 'label' => 'Token URL Base', 'section' => 'esn_general_section' ],
		'confirm_slug'          => [ 'label' => 'Confirm Slug', 'section' => 'esn_general_section' ],
		'unsubscribe_slug'      => [ 'label' => 'Unsubscribe Slug', 'section' => 'esn_general_section' ],
		'batch_size'            => [ 'label' => 'Batch Size', 'section' => 'esn_general_section', 'type' => 'number' ],
		'send_delay'            => [ 'label' => 'Send Delay (seconds)', 'section' => 'esn_general_section', 'type' => 'number' ],
		'show_dashboard_widget' => [ 'label' => 'Show Dashboard Widget', 'section' => 'esn_general_section', 'type' => 'checkbox' ],
		'notification_subject'  => [ 'label' => 'Notification Subject', 'section' => 'esn_email_section' ],
		'confirmation_subject'  => [ 'label' => 'Confirmation Subject', 'section' => 'esn_email_section' ],
		'from_name'             => [ 'label' => 'From Name', 'section' => 'esn_email_section' ],
		'from_email'            => [ 'label' => 'From Email', 'section' => 'esn_email_section' ],
	];

	foreach ( $fields as $key => $field ) {
		add_settings_field(
			$key,
			$field['label'],
			'esn_render_settings_field',
			'esn-settings',
			$field['section'],
			[
				'key'  => $key,
				'type' => $field['type'] ?? 'text',
			]
		);
	}
}

add_action( 'admin_init', 'esn_register_settings' );

function esn_sanitize_settings( array $input ): array {
	$defaults = esn_get_default_settings();
	$current  = esn_get_settings();

	$output = [
		'subscribe_slug'        => sanitize_title( $input['subscribe_slug'] ?? $defaults['subscribe_slug'] ),
		'subscriptions_base'    => sanitize_title( $input['subscriptions_base'] ?? $defaults['subscriptions_base'] ),
		'confirm_slug'          => sanitize_title( $input['confirm_slug'] ?? $defaults['confirm_slug'] ),
		'unsubscribe_slug'      => sanitize_title( $input['unsubscribe_slug'] ?? $defaults['unsubscribe_slug'] ),
		'batch_size'            => max( 1, (int) ( $input['batch_size'] ?? $defaults['batch_size'] ) ),
		'send_delay'            => max( 10, (int) ( $input['send_delay'] ?? $defaults['send_delay'] ) ),
		'notification_subject'  => sanitize_text_field( $input['notification_subject'] ?? $defaults['notification_subject'] ),
		'confirmation_subject'  => sanitize_text_field( $input['confirmation_subject'] ?? $defaults['confirmation_subject'] ),
		'from_name'             => sanitize_text_field( $input['from_name'] ?? $defaults['from_name'] ),
		'from_email'            => sanitize_email( $input['from_email'] ?? $defaults['from_email'] ),
		'show_dashboard_widget' => empty( $input['show_dashboard_widget'] ) ? 0 : 1,
	];

	foreach ( [ 'subscribe_slug', 'subscriptions_base', 'confirm_slug', 'unsubscribe_slug' ] as $key ) {
		if ( $current[ $key ] !== $output[ $key ] ) {
			update_option( ESN_REWRITE_FLUSH_FLAG, 1, false );
			break;
		}
	}

	return $output;
}

function esn_render_settings_field( array $args ): void {
	$key      = $args['key'];
	$type     = $args['type'];
	$settings = esn_get_settings();
	$value    = $settings[ $key ] ?? '';
	$name     = ESN_OPTION_KEY . '[' . $key . ']';

	if ( 'checkbox' === $type ) {
		echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( (int) $value, 1, false ) . '> Enabled</label>';
		return;
	}

	$input_type = 'number' === $type ? 'number' : 'text';
	echo '<input class="regular-text" type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';

	if ( 'notification_subject' === $key ) {
		echo '<p class="description">Use <code>{event_title}</code> to include the published event title.</p>';
	}
}

function esn_handle_admin_subscriber_actions(): void {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( empty( $_GET['page'] ) || 'esn-subscribers' !== $_GET['page'] ) {
		return;
	}

	if ( empty( $_GET['esn_action'] ) || empty( $_GET['subscriber_id'] ) ) {
		return;
	}

	check_admin_referer( 'esn_subscriber_action' );

	$action        = sanitize_key( wp_unslash( $_GET['esn_action'] ) );
	$subscriber_id = absint( $_GET['subscriber_id'] );

	if ( 'delete' === $action ) {
		esn_delete_subscriber( $subscriber_id );
	}

	if ( 'resend' === $action ) {
		global $wpdb;
		$table_name = esn_get_subscribers_table_name();
		$subscriber = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d LIMIT 1",
				$subscriber_id
			),
			ARRAY_A
		);

		if ( $subscriber ) {
			esn_update_subscriber(
				$subscriber_id,
				[
					'status' => 'pending',
					'token'  => esn_generate_token(),
				]
			);
			$subscriber = esn_get_subscriber_by_email( $subscriber['email'] );
			if ( $subscriber ) {
				esn_send_confirmation_email( $subscriber );
			}
		}
	}

	wp_safe_redirect( admin_url( 'admin.php?page=esn-subscribers' ) );
	exit;
}

add_action( 'admin_init', 'esn_handle_admin_subscriber_actions' );

function esn_add_admin_pages(): void {
	add_menu_page(
		'Event Notifications',
		'Event Notifications',
		'manage_options',
		'esn-subscribers',
		'esn_render_subscribers_page',
		'dashicons-email-alt2'
	);

	add_submenu_page(
		'esn-subscribers',
		'Subscribers',
		'Subscribers',
		'manage_options',
		'esn-subscribers',
		'esn_render_subscribers_page'
	);

	add_submenu_page(
		'esn-subscribers',
		'Settings',
		'Settings',
		'manage_options',
		'esn-settings',
		'esn_render_settings_page'
	);
}

add_action( 'admin_menu', 'esn_add_admin_pages' );

function esn_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$counts = esn_get_subscriber_counts();
	?>
	<div class="wrap">
		<h1>Event Notifications Settings</h1>
		<p>Manage the subscription flow, email settings, and public URLs used by the plugin.</p>

		<div class="notice notice-info inline">
			<p><strong>Subscribe URL:</strong> <a href="<?php echo esc_url( esn_get_subscribe_url() ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( esn_get_subscribe_url() ); ?></a></p>
			<p><strong>Subscribers:</strong> <?php echo esc_html( (string) $counts['subscribed'] ); ?> subscribed, <?php echo esc_html( (string) $counts['pending'] ); ?> pending</p>
		</div>

		<form action="options.php" method="post">
			<?php
			settings_fields( 'esn_settings_group' );
			do_settings_sections( 'esn-settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

function esn_render_subscribers_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$page_number = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page    = 25;
	$offset      = ( $page_number - 1 ) * $per_page;
	$total       = esn_count_all_subscribers( $search );
	$subscribers = esn_get_subscribers( $per_page, $offset, $search );
	$counts      = esn_get_subscriber_counts();
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	?>
	<div class="wrap">
		<h1>Subscribers</h1>
		<p>Manage event notification subscribers separately from WordPress site users.</p>

		<ul style="display:flex;gap:16px;padding:0;margin:16px 0;list-style:none;">
			<li><strong>Subscribed:</strong> <?php echo esc_html( (string) $counts['subscribed'] ); ?></li>
			<li><strong>Pending:</strong> <?php echo esc_html( (string) $counts['pending'] ); ?></li>
			<li><strong>Unsubscribed:</strong> <?php echo esc_html( (string) $counts['unsubscribed'] ); ?></li>
		</ul>

		<form method="get" style="margin:16px 0;">
			<input type="hidden" name="page" value="esn-subscribers">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search email address">
			<?php submit_button( 'Search', 'secondary', '', false ); ?>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th>Email</th>
					<th>Status</th>
					<th>Source</th>
					<th>Created</th>
					<th>Confirmed</th>
					<th>Last Sent</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $subscribers ) ) : ?>
					<tr><td colspan="7">No subscribers found.</td></tr>
				<?php else : ?>
					<?php foreach ( $subscribers as $subscriber ) : ?>
						<?php $action_url = wp_nonce_url( admin_url( 'admin.php?page=esn-subscribers&subscriber_id=' . (int) $subscriber['id'] ), 'esn_subscriber_action' ); ?>
						<tr>
							<td><?php echo esc_html( $subscriber['email'] ); ?></td>
							<td><?php echo esc_html( ucfirst( $subscriber['status'] ) ); ?></td>
							<td><?php echo esc_html( $subscriber['source'] ); ?></td>
							<td><?php echo esc_html( $subscriber['created_at'] ); ?></td>
							<td><?php echo esc_html( $subscriber['confirmed_at'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $subscriber['last_sent_at'] ?: '—' ); ?></td>
							<td>
								<a href="<?php echo esc_url( $action_url . '&esn_action=resend' ); ?>">Resend confirmation</a>
								|
								<a href="<?php echo esc_url( $action_url . '&esn_action=delete' ); ?>" onclick="return confirm('Delete this subscriber?');">Delete</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav" style="margin-top:16px;">
				<div class="tablenav-pages">
					<span class="displaying-num"><?php echo esc_html( (string) $total ); ?> items</span>
					<span class="pagination-links">
						<?php for ( $page = 1; $page <= $total_pages; $page++ ) : ?>
							<?php $url = add_query_arg( [ 'page' => 'esn-subscribers', 'paged' => $page, 's' => $search ], admin_url( 'admin.php' ) ); ?>
							<a class="<?php echo $page === $page_number ? 'button button-primary' : 'button'; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) $page ); ?></a>
						<?php endfor; ?>
					</span>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

function esn_widget(): void {
	if ( ! current_user_can( 'manage_options' ) || ! esn_get_setting( 'show_dashboard_widget' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'esn_dashboard_qr_widget',
		'Event Subscription QR',
		'esn_dashboard_qr_widget_callback'
	);
}

add_action( 'wp_dashboard_setup', 'esn_widget' );

function esn_dashboard_qr_widget_callback(): void {
	$image_url = plugins_url( 'qr.png', __FILE__ );
	echo '<img src="' . esc_url( $image_url ) . '" alt="Subscribe QR Code" style="display:block;margin:0 auto;max-width:100%;height:auto;" />';
	echo '<p>Public subscribe page: <a href="' . esc_url( esn_get_subscribe_url() ) . '" target="_blank" rel="noreferrer">' . esc_html( esn_get_subscribe_url() ) . '</a></p>';
	echo '<p>Use this QR image on flyers or posters so visitors can reach the subscribe page quickly.</p>';
}

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=esn-settings' ) ) . '">Settings</a>' );
		return $links;
	}
);
