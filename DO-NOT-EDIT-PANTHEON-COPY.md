# Do Not Edit the Pantheon Copy

The GitHub repository is the source of truth for CSA WP User Tracker:

```text
https://github.com/ashburn2k/csa-wp-user-tracker
```

Do not make direct plugin changes in the Pantheon checkout at:

```text
wp-content/plugins/csa-wp-user-tracker
```

Make changes in the GitHub plugin repository, then sync them to Pantheon with:

```bash
bin/sync-to-pantheon.sh --commit --push
```
