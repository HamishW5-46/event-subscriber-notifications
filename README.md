# Event Subscriber Notifications

WordPress plugin for collecting event-notification subscribers, sending double opt-in confirmation emails, and notifying confirmed subscribers when new `tribe_events` posts are published.

## Features

- Separate subscriber storage in a dedicated database table
- Public subscribe page and shortcode form
- Double opt-in confirmation flow
- Unsubscribe links in outgoing emails
- Batched event notification sending
- WordPress admin screens for subscriber management and plugin settings
- Optional dashboard QR widget for the public subscribe page

## Plugin Structure

- `event-subscriber-notifications.php`: main plugin logic
- `event-template.php`: default Events Calendar editor template integration
- `templates/email-notification.php`: outgoing event email template
- `templates/opt-in-confirmation.php`: opt-in confirmation email template
- `qr.png`: QR image for the dashboard widget

## Admin Areas

After activation, the plugin adds an `Event Notifications` menu in WordPress admin with:

- `Subscribers`: search, review, resend confirmation, and delete subscriber records
- `Settings`: configure slugs, send behavior, email subjects, sender identity, and dashboard widget visibility

## Public Subscription Flow

The plugin supports:

- A public subscribe page using the configured subscribe slug
- A shortcode: `[esn_optin_form]`

New subscribers are stored in the plugin table with `pending` status until they confirm by email. Confirmed subscribers move to `subscribed`. Unsubscribed addresses remain in the table with `unsubscribed` status.

## Event Notification Flow

When a new The Events Calendar event is published:

1. A scheduled job is queued.
2. Confirmed subscribers are processed in batches.
3. Each subscriber receives the event email with:
   - event title
   - event excerpt, or a trimmed content fallback
   - event link
   - unsubscribe link

## Development Notes

- The plugin stores mailing-list subscribers in its own database table instead of WordPress user accounts.
- The plugin auto-creates its subscriber table on activation and also checks for it on load.
- Rewrite changes may still require a permalink refresh in WordPress if URLs appear stale.
