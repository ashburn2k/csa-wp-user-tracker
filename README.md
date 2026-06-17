# CSA WP User Tracker

Tracks logged-in WordPress activity for users whose roles are not limited to `subscriber`.

## Development Notice

Do not edit the Pantheon checkout copy directly at `wp-content/plugins/csa-wp-user-tracker`. Make plugin changes in this GitHub repository first:

```text
https://github.com/ashburn2k/csa-wp-user-tracker
```

Then sync the plugin into Pantheon with the release workflow or `bin/sync-to-pantheon.sh --commit --push`.

## What It Logs

- Login, failed login, and logout for protected roles.
- Front-end page views while a protected-role user is logged in.
- Admin screen views and non-heartbeat AJAX requests.
- Authenticated REST requests.
- Post, page, custom post type, attachment, term, comment, and user changes.
- User role changes.
- Option changes by option name only, excluding noisy internal options and transients.
- Plugin activation/deactivation, theme switches, and upgrader operations.
- Successful email update deliveries triggered by matching content events.

## Privacy Notes

- Raw IP addresses are not stored. The plugin stores an HMAC hash using the WordPress auth salt.
- Request bodies, cookies, passwords, nonces, tokens, and secret-like values are not stored.
- Option values are not stored.
- Logs are retained for 180 days by default. Use the `csa_wp_user_tracker_retention_days` filter to change retention.

## Admin UI

After activation, go to **Tools > CSA WP User Tracker**. Users need `manage_options` by default. Use the `csa_wp_user_tracker_admin_capability` filter to change the capability.

The activity list shows plain-English action, object, and request labels for non-technical users, while keeping the raw stored action and object type visible in small text for filtering and troubleshooting. WordPress trash events display as deleted and moved to Trash; permanent delete events display as deleted permanently.

### Email Updates

The admin page includes email update rules for content changes. Enable email updates, add one or more recipients, and choose whether to watch post changes, page changes, or both. Matching can be scoped to any tracked user, one user by ID/login/email, or selected roles.

Delivery can be set to:

- **Once triggered**: sends an email immediately after a matching post/page content event is logged.
- **Daily digest**: queues matching events and sends them through WP-Cron once per day.
- **Weekly digest**: queues matching events and sends them through WP-Cron once per week.

Matching content events include create, update, status change, trash, restore, and delete actions for selected post types.

Email messages show the page/post title and public link instead of the internal WordPress object ID. New content-event log rows store the permalink so trash and permanent-delete notifications can still include the page/post link when available.

The digest queue and email settings are stored in WordPress options and are excluded from activity logging.

Use **Send Test Email** after saving recipients to verify the site can send mail through the configured WordPress mailer.

## Release Workflow

This repository is the source of truth for the plugin. The Pantheon site receives a copy at:

```text
wp-content/plugins/csa-wp-user-tracker
```

To package the plugin locally:

```bash
bin/build-zip.sh
```

To sync local changes into the Pantheon checkout:

```bash
bin/sync-to-pantheon.sh --commit --push
```

To publish through GitHub Actions:

```bash
git tag v0.1.2
git push origin v0.1.2
```

Tag pushes build a release ZIP and upload it to the GitHub release. To sync the installed Pantheon copy, run the `Release and Sync to Pantheon` workflow manually with `sync_pantheon` enabled.

The GitHub repository must have this secret for Pantheon sync:

```text
PANTHEON_SSH_PRIVATE_KEY
```

Use the private key whose public key is already added to Pantheon for this site.

To set that secret from this machine:

```bash
bin/setup-github-secret.sh
```

## WordPress Update Checks

The plugin checks GitHub releases for updates. Public repositories work without extra setup. For a private GitHub repository, define this token on the WordPress site before testing plugin updates from WP Admin:

```php
define( 'CSA_WP_USER_TRACKER_GITHUB_TOKEN', 'github-token-with-release-access' );
```

On Pantheon, create a site-owned secret named `CSA_WP_USER_TRACKER_GITHUB_TOKEN` with type `runtime` and scope `web`. The updater also reads this value with `pantheon_get_secret()`.
