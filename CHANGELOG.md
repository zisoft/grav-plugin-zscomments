# v1.4.0
## Unreleased
1. [](#improved)
    * **Moderation emails now link to the admin2 moderation page** instead of embedding approve/delete forms (new translation keys `EMAIL_MODERATE` and `EMAIL_MODERATE_IN_ADMIN`).
2. [](#bugfix)
    * **Security: removed the unauthenticated `/zscomments-approve` and `/zscomments-delete` routes.** Anyone knowing a published comment id (it is in the page HTML) could delete it, or approve it with a `quickreply` posted under the site owner's name. They also redirected to the unvalidated `url` parameter (open redirect). Moderation now goes through the admin2 API only (`api.super`).
    * **Security: the comment file is no longer built from client-supplied form fields.** `data[path]` and `data[lang]` were used to build the YAML file name without rejecting `..` or unknown languages, allowing writes outside the commented page. The route and the language now come from the current request, route segments are validated and the language must be one of `system.languages.supported`. The page title is also taken from the page.
    * **Reading comments no longer creates empty folders.** Any URL under `enable_on_routes`, including 404s and bot probes, created the folder tree of its would-be comment file. Comments are now neither read nor written for unroutable pages, and reading a missing file takes no lock and creates nothing.
    * **Moderation actions no longer create or rewrite comment files needlessly.** Approving or deleting on a route without a comment file created the folders and a YAML file containing `null`, and an unknown comment id rewrote the file anyway. `updateCommentInFile()` now returns early when the file is missing, and `updateYamlFileWithLock()` skips the write when nothing changed.
    * **Fixed the unreadable quick reply field in the admin2 dark theme.** The textarea forced a white background without a text color, which showed white text on white; it now uses the component's text and placeholder colors.

# v1.3.2
## 11/18/2026
1. [](#bugfix)
    * **Added an `id` attribute to the comment form.** Captcha scripts such as Cloudflare Turnstile need it to locate and submit the form. The value is the Grav form object's `id` or `name`, falling back to the configured form's `id`, then its `name`.
1. [](#improved)
    * **Documented Cloudflare Turnstile as an alternative to reCAPTCHA** in the README.

# v1.3.1
## 10/05/2026
1. [](#bugfix)
    * **Fix PHP 8.2 dynamic property deprecation warning**

# v1.3.0
## 07/14/2026
1. [](#new)
    * **Added a honeypot field for spam protection.** A new `honeypot_field_name` config option lets you name a hidden form field that real visitors never fill in; if a submission arrives with that field non-empty, the whole form process (save + notification email) is cancelled silently.
    * **Added a `blocked_scripts` regex filter.** Comments whose text or name match this admin-configured regular expression (e.g. `\p{Cyrillic}` to reject a different script) are cancelled before saving. An invalid pattern fails open — the filter is skipped and a warning is logged — instead of blocking every comment.

# v1.2.1
## 07/13/2026
1. [](#bugfix)
    * **Sanitized comment text, author, and email as UTF-8 before saving.** A browser or bot submitting non-UTF-8 bytes (e.g. Latin-1) would sit unnoticed in the YAML file until an admin opened the comments list, silently breaking the whole response instead of just that one comment. Ill-formed byte sequences are now replaced at save time.

# v1.2.0
## 07/09/2026
1. [](#new)
    * **Added an option to collect the visitor's IP address.** A new `collect_ip` config toggle (disabled by default) controls whether the IP is stored with each comment; the admin field help reminds you to mention this in your privacy policy if you enable it.
    * **Redesigned the comments list as a card layout**, with the page title now linking to the commented page and a hover effect on cards.
2. [](#improved)
    * **Refactored the comment-approval email template** into a dedicated `zscomments_email.html.twig` partial for clearer, more maintainable notifications.
3. [](#bugfix)
    * **Fixed an infinite recursion crash when rendering legacy comments without an `id`/`parent_id`.** Comments missing these fields all matched as children of one another under Twig's loose `==` comparison, causing a stack overflow as soon as the comments partial rendered; they are now displayed flat instead.
    * **Hid the reply button on comments without an id**, since those comments can't actually be replied to.
    * **Fixed `title`/`lang` fields falling back to literal `{{ }}`/`{% %}` Twig expressions** instead of the actual page value after a bad migration.

# v1.1.6
## 06/27/2026
1. [](#bugfix)
    * **Fixed version tag**

# v1.1.5
## 06/26/2026
1. [](#bugfix)
    * **Fixed Grav 2.0.3 compatibility issues.** After upgrading to Grav 2.0.3, the `evaluate()` Twig function used in form field evaluation stopped working correctly, causing literal Twig expressions like `{{ grav.page.header.title }}` and `{{ grav.uri.path }}` to be stored instead of actual values. This prevented comments from being properly saved and approved. Fixed by directly accessing Twig variables in the form template for `title`, `lang`, and `path` fields, and added server-side validation to detect and correct any unevaluated Twig syntax.
    * **Fixed PHP 8.4+ compatibility.** Replaced deprecated `FILTER_SANITIZE_STRING` constant (removed in PHP 8.4) with `trim(strip_tags())` for form data sanitization, ensuring compatibility with PHP 8.5+.

# v1.1.4
## 06/14/2026
1. [](#bugfix)
    * **Harden frontend form registration against stale cache state.** The comments form could occasionally render with an empty `__form-name__`, causing submits to silently reload the page without saving or sending email until cache was cleared. The plugin now registers its form explicitly on the current page during request handling and renders the hidden form name directly from plugin config so submissions remain stable even when cached page/form state gets out of sync.

# v1.1.3
## 06/12/2026
1. [](#bugfix)
    * **Normalize comment storage routes to avoid trailing-slash path issues.** Comment submissions on routes like `/holzwerken/projekte/couchtisch/` could be stored in a wrong location such as `user/data/zscomments/.../couchtisch/.yaml`, which created a stray directory and made comments appear unsaved. Routes are now normalized consistently for writes, reads, and cache keys so `/foo` and `/foo/` resolve to the same comment file.

# v1.1.2
## 05/29/2026
1. [](#bugfix)
    * **Preserve default form buttons when saving plugin config in admin2.** Saving `user/config/plugins/zscomments.yaml` from admin2 can write only the overridden `form` subtree, which drops `form.buttons` from the effective config and made the frontend submit button disappear. The plugin now merges the default form definition from `plugins/zscomments/zscomments.yaml` with the saved config at runtime, and the Twig partial includes a submit-button fallback if no buttons are configured.

# v1.1.1
## 05/22/2026
1. [](#improved)
    * **Updated version handling**

# v1.1.0
## 05/17/2026
1. [](#new)
    * **Config section in admin panel** The plugin is now fully configurable in Grav's Admin2 panel.

# v1.0.0
## 05/16/2026
1. [](#new)
    * Initial Release
