# Grav ZSComments Plugin

[![Grav](https://img.shields.io/badge/Grav-2.x-221E1F?logo=grav&logoColor=white)](https://getgrav.org)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Admin](https://img.shields.io/badge/Admin-admin2-6f42c1)](https://github.com/getgrav/grav-plugin-admin2)

**ZSComments** is a comments plugin for **Grav 2**.

It started as a fork of the original Grav Comments plugin, but has been adapted for modern Grav 2 projects with **admin2 moderation**, **YAML-based storage**, **threaded replies**, and **safer file locking for shared hosting**.

## Highlights

- add comments to Grav pages from the frontend
- threaded comment replies
- optional moderation / approval workflow
- admin2 moderation UI
- quick reply while approving a comment
- moderation email notifications with approve/delete actions
- configurable frontend comment order (`asc` / `desc`)
- route-based enable / disable rules
- Gravatar support
- YAML storage in `user/data/zscomments`
- lock-directory based concurrent file access without relying on `flock()`
- optional reCAPTCHA integration via Grav Form

## Requirements

- Grav `2.x`
- [Form plugin](https://github.com/getgrav/grav-plugin-form)
- [Email plugin](https://github.com/getgrav/grav-plugin-email)
- [admin2 plugin](https://github.com/getgrav/grav-plugin-admin2) recommended for configuration and moderation

## Installation

Install with GPM:

```bash
bin/gpm install zscomments
```

Or clone / copy this plugin into:

```text
user/plugins/zscomments
```

## Quick start

1. Install and enable the plugin.
2. Configure it in **admin2** or in `user/config/plugins/zscomments.yaml`.
3. Add the comments partial to the page template where comments should appear.
4. Make sure the **Email plugin** is configured correctly if you want moderation emails.

Add the partial to a Twig template:

```twig
{% include 'partials/zscomments.html.twig' with { page: page } %}
```

Example inside a blog item template:

```twig
{% block content %}
    {% include 'partials/blog_item.html.twig' with { blog: page.parent, truncate: false } %}
    {% include 'partials/zscomments.html.twig' with { page: page } %}
{% endblock %}
```

## Configuration

You can configure ZSComments either:

- in **admin2**
- or in `user/config/plugins/zscomments.yaml`

### Main options

| Option               |                          Default | Description |
|----------------------|---------------------------------:|---|
| `enabled`            |                           `true` | Enables the plugin. |
| `require_approval`   |                           `true` | New comments are stored as pending until approved. |
| `collect_ip`         |                          `false` | Collect IP address of the visitor. Please note that this may be sensitive data and should be handled with care. Mention this in your privacy policy if you enable this option. |
| `approval_email`     |               `mail@example.com` | Recipient for moderation emails. |
| `approval_from`      | `Grav CMS <noreply@example.com>` | Sender used for moderation emails. Use a valid sender address for your mail setup. |
| `approval_subject`   |                    `New comment` | Subject line for moderation emails. |
| `quickreply_name`    |                      `Your name` | Author name used for quick replies created during approval. |
| `quickreply_email`   |               `mail@example.com` | Email used for quick replies created during approval. |
| `comment_order`      |                           `desc` | Frontend output order for approved comments: `asc` or `desc`. |
| `enable_on_routes`   |                          `['/']` | Route prefixes where the plugin should be active. |
| `disable_on_routes`  |                             `[]` | Exact routes where the plugin should stay disabled even if matched by `enable_on_routes`. |
| `lock_timeout`       |                              `5` | Maximum wait time in seconds for a comment file lock. |
| `lock_retry_delay`   |                         `100000` | Delay between lock retries in microseconds. |
| `lock_stale_timeout` |                             `30` | Removes stale lock directories after this many seconds. |

### Example configuration

```yaml
enabled: true
require_approval: true
comment_order: desc
approval_email: mail@example.com
approval_from: 'My Site <noreply@example.com>'
approval_subject: 'New comment waiting for approval'

enable_on_routes:
  - /blog
  - /news

disable_on_routes:
  - /blog/archive
```

### Route matching behavior

- `enable_on_routes` works as a route prefix list
- `disable_on_routes` is checked as an exact route list

So this is a common setup:

```yaml
enable_on_routes:
  - /
```

That enables comments everywhere unless a route is explicitly disabled.

## Admin2 moderation

When **admin2** is installed, ZSComments adds its own plugin page in the admin interface.

From there you can:

- review recent comments
- filter by:
  - pending only
  - time range
  - route
  - text search
- approve comments
- delete comments
- add an optional quick reply during approval
- see recently commented pages

## Email notifications

When a comment is submitted, ZSComments can send a moderation email using the **Email plugin**.

Important:

- the **Email plugin** provides the actual mail transport
- ZSComments provides the moderation-specific recipient, sender, and subject
- moderation mails contain approve/delete actions
- an optional quick reply can be added while approving

Relevant ZSComments settings:

- `approval_email`
- `approval_from`
- `approval_subject`

## Comment storage

Comments are stored as YAML files in:

```text
user/data/zscomments
```

On multilingual sites, comments are stored per language as well.

Examples:

```text
user/data/zscomments/de/blog/my-post.yaml
user/data/zscomments/en/blog/my-post.yaml
```

Benefits of this approach:

- no database required
- simple backups
- easy manual inspection
- one comment file per page route

## Frontend behavior

The frontend output is rendered by:

```text
plugins/zscomments/templates/partials/zscomments.html.twig
```

Public output currently behaves like this:

- only approved comments are shown
- comments can be shown oldest-first or newest-first
- replies are rendered as a nested thread
- name and date fields are always visible
- comment sender email is never puplished
- avatars are rendered via Gravatar if available

## reCAPTCHA

The default plugin configuration includes a commented example for reCAPTCHA integration.

To enable it:

1. copy or edit `user/config/plugins/zscomments.yaml`
2. uncomment the captcha field in the form definition
3. uncomment the captcha process block
4. add your own reCAPTCHA site key and secret

## Known limitations

- The plugin is designed for **Grav 2** and **admin2** workflows; classic Grav Admin integration is not included.
- `disable_on_routes` is matched as an exact route list, while `enable_on_routes` works by prefix.
- Public frontend output only shows approved comments.
- There is no database backend; comments are stored in YAML files per route.
- Moderation by email is intended for trusted recipients because approval and delete actions are triggered from moderation links/forms.

## Migration from the original `comments` plugin

ZSComments was renamed deliberately to avoid conflicts with the original Grav plugin.

If you are migrating from `comments`, check these points:

- plugin folder changed from `user/plugins/comments` to `user/plugins/zscomments`
- config file changed from `user/config/plugins/comments.yaml` to `user/config/plugins/zscomments.yaml`
- Twig partial changed from `partials/comments.html.twig` to `partials/zscomments.html.twig`
- comment storage changed from `user/data/comments` to `user/data/zscomments`
- admin integration is now focused on **admin2**

Template include update:

```twig
{# old #}
{% include 'partials/comments.html.twig' with { page: page } %}

{# new #}
{% include 'partials/zscomments.html.twig' with { page: page } %}
```

If you already have legacy comment data, move or convert it into the `user/data/zscomments` structure before removing the old plugin.

## Notes

- The plugin is intentionally named **`zscomments`** to avoid conflicts with the original Grav `comments` plugin.
- It is designed for **Grav 2** and **admin2** workflows.
- File locking is implemented with lock directories for better compatibility with shared hosting environments where `flock()` can be unreliable.

## License

MIT — see [LICENSE](LICENSE).
