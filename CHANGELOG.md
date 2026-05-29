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
