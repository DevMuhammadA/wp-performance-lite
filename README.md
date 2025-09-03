# WP Performance Lite

Safe, toggleable performance tweaks for WordPress:
- Disable emojis (front & admin)
- Disable oEmbed discovery/REST routes
- Trim `wp_head` (shortlink, RSD, wlwmanifest, generator)
- Remove Dashicons for non-logged-in visitors
- Only load `comment-reply` when needed
- Front-end Heartbeat control (default/reduce/disable)
- DNS prefetch / preconnect list

## Installation
1. Upload to `wp-content/plugins/` or install via **Plugins → Add New → Upload Plugin**.
2. Activate **WP Performance Lite**.
3. Go to **Settings → Performance Lite** and toggle features.

## Notes
- Conservative defaults, theme-agnostic.
- Admin pages remain unchanged (except emoji removal if enabled).
- Heartbeat setting only affects front-end (admin left intact).

## Changelog
- 1.0.0 — Initial release
