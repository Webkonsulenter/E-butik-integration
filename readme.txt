=== E-butik regnskabs-integration ===
Contributors: jenskirk
Tags: woocommerce, e-conomic, integration
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a "Kør integration" button to the WooCommerce orders list.

== Description ==

Adds a single action button to the WooCommerce orders list that re-sends an order
to the E-butik accounting integration, and can create the webhook the integration
needs. Only the Site ID is configurable; endpoint, icon and webhook URL are fixed.

The plugin registers no front-end hooks, so it has no effect on checkout.

== Changelog ==

= 3.4.0 =
* Renamed the internal handle from "ebutik" to "e-butik" (e_butik for PHP identifiers).
* Existing settings are migrated automatically from the old option key.

= 3.3.0 =
* Button icon fixed to "Åbn eksternt"; icon picker removed.
* Endpoint is now hardcoded and no longer a setting.
* Removed the URL preview from the settings page.
* Added a button that creates the order.updated webhook, without duplicating an existing one.

= 3.2.0 =
* Updates are now delivered from GitHub.
* Added readme.txt so release notes appear in the "View details" modal.

= 3.1.0 =
* Settings are kept when the plugin is deleted, unless cleanup is explicitly enabled.

= 3.0.1 =
* Title lowercased, code comments translated to English, author links point to webkonsulenterne.dk.

= 3.0.0 =
* Rebranded from WooCommerceGuru to E-butik.
* Button renamed to "Kør integration" and now opens in a new tab.
