# CSA WP User Tracker

Tracks logged-in WordPress activity for users whose roles are not limited to `subscriber`.

## What It Logs

- Login, failed login, and logout for protected roles.
- Front-end page views while a protected-role user is logged in.
- Admin screen views and non-heartbeat AJAX requests.
- Authenticated REST requests.
- Post, page, custom post type, attachment, term, comment, and user changes.
- User role changes.
- Option changes by option name only, excluding noisy internal options and transients.
- Plugin activation/deactivation, theme switches, and upgrader operations.

## Privacy Notes

- Raw IP addresses are not stored. The plugin stores an HMAC hash using the WordPress auth salt.
- Request bodies, cookies, passwords, nonces, tokens, and secret-like values are not stored.
- Option values are not stored.
- Logs are retained for 180 days by default. Use the `esnet_activity_tracker_retention_days` filter to change retention.

## Admin UI

After activation, go to **Tools > CSA WP User Tracker**. Users need `manage_options` by default. Use the `esnet_activity_tracker_admin_capability` filter to change the capability.

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

The `Release and Sync to Pantheon` workflow builds a release ZIP, uploads it to the GitHub release, then pushes the plugin folder into the Pantheon Git repository. The GitHub repository must have this secret:

```text
PANTHEON_SSH_PRIVATE_KEY
```

Use the private key whose public key is already added to Pantheon for this site.

To set that secret from this machine:

```bash
bin/setup-github-secret.sh
```
