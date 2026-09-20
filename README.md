# E-butik regnskabs-integration

Adds a single **Kør integration** button to the WooCommerce orders list, and can create
the webhook the integration depends on. Only the Site ID is configurable.

## What it does

- **Orders list button.** One action button per order, opening `order_reload` on e-butik.dk in a new tab. Icon is `dashicons-external`, endpoint and icon are both fixed in code.
- **Settings page** under *Indstillinger → E-butik Integration*: Site ID, and whether to remove settings on uninstall.
- **Webhook button.** Creates the `order.updated` webhook pointing at `https://e-butik.dk/webhooks/woocommerce.php`, or activates it if it exists but is paused/disabled. Matching is on topic plus delivery URL, so pressing it twice cannot create a duplicate.
- **GitHub updates** via bundled `plugin-update-checker` 5.7.

Note: WooCommerce's own webhook screen sends a test delivery when a webhook is activated.
That is deliberately skipped here — it is a blocking HTTP call, and a slow receiving
endpoint would hang the admin request for as long as it takes to respond.

## Performance

The whole plugin sits behind an `is_admin()` guard, so a front-end request — checkout and
"Godkend ordre" included — parses the file and returns immediately: no hooks, no options,
no queries. The only exceptions are the `before_woocommerce_init` HPOS declaration and the
update checker, which loads on admin, cron and WP-CLI requests only.

In the admin, the option lookup, capability check and URL construction happen once per page
load and are cached in a static. Each order row does a single string concatenation. The icon
CSS and new-tab script are printed only on the orders list, only when the button renders.

## Configuration

| What | Where |
|---|---|
| Site ID | Settings page (required; button hidden until set) |
| Endpoint | Hardcoded: `E_Butik_Integration::ENDPOINT` |
| Button icon | Hardcoded: `E_Butik_Integration::ICON_GLYPH` (`f504`) |
| Webhook URL and topic | Hardcoded: `WEBHOOK_URL`, `WEBHOOK_TOPIC` |
| GitHub repo | `E_BUTIK_INTEGRATION_REPO` constant, overridable in `wp-config.php` |
| Plugin folder / slug | `e-butik-integration` — must not change, or updates orphan the old folder |
| GitHub token (private repo) | `E_BUTIK_INTEGRATION_GITHUB_TOKEN` in `wp-config.php` — never commit it |

## Uninstall behaviour

| Action | Settings |
|---|---|
| Deaktiver | always kept — `uninstall.php` does not run |
| Slet, box unchecked (default) | kept |
| Slet, box ticked | removed |
| Uploading a new zip over the old one | kept — that is an upgrade, not an uninstall |

## Releasing

Bump `Version:` in the plugin header and `Stable tag:` in `readme.txt` to the same number,
add a changelog entry, commit, then push a `v`-prefixed tag:

```bash
git tag v3.5.0 && git push origin main --follow-tags
```

`.github/workflows/release.yml` does the rest: it refuses the tag if the header, the
`Stable tag:` and the tag itself disagree, builds `e-butik-integration.zip` with
`e-butik-integration/` as its top-level folder, and publishes a GitHub release with that
zip attached. Client sites pick the update up within 12 hours, or immediately via
"Check again" on the updates screen.

Two different zips are in play, deliberately:

| Used for | Which zip | Folder inside |
|---|---|---|
| Automatic updates | GitHub's own source archive | `E-butik-integration-3.5.0/`, rewritten to `e-butik-integration/` by the update library's `upgrader_source_selection` hook |
| Manual upload | the release asset built by the workflow | already `e-butik-integration/` |

That is why `enableReleaseAssets()` stays commented out: turning it on would make every
update depend on the asset, and the tags pushed before this workflow existed have none.

Never hand anyone the zip from GitHub's green "Code → Download ZIP" button. It extracts as
`E-butik-integration-main/`, which WordPress treats as a separate second plugin — two
copies active, two buttons on every order row.

## Still open

- The order button is an unauthenticated GET. `site_id` and `order_id` go to `order_reload`
  as plain query parameters, with nothing proving the request came from the shop. The
  `edit_shop_orders` check in this plugin only decides whether the button is drawn; it does
  not protect the endpoint. Anyone who learns a `site_id` can replay `order_reload` for
  arbitrary order IDs. Closing it needs a shared secret or a signed timestamp verified at
  `e-butik.dk`, so it cannot be fixed on the WordPress side alone.
- The handler at `e-butik.dk/webhooks/woocommerce.php` takes ~23 s on first contact with an order. Asynchronous delivery hides that from the customer but does not remove it. The tight 23.2–23.7 s band points at a fixed timeout or sleep loop rather than real work.
- `action.woocommerce_payment_complete` is redundant and can be switched off: it carries only `{action, arg}`, and `WC_Order::payment_complete()` calls `save()` — firing `order.updated` with the full paid order — on the line immediately before it.
- Once the handler upserts instead of assuming create-before-update, `order.created` can go too, leaving `order.updated` alone.

## Naming

Three names, deliberately different:

| Name | Value | Why |
|---|---|---|
| GitHub repo | `E-butik-integration` | cosmetic; only affects the repo URL |
| Plugin folder and slug | `e-butik-integration` | the directory on every client site, the text domain, and the settings page slug |
| Option key | `e_butik_integration_settings` | left unchanged on purpose — renaming it would discard the saved Site ID |

Renaming the plugin folder makes WordPress treat it as a new plugin. Deactivate and delete
any previously installed `e-butik-integration` before activating this, or both will run and
you will get two buttons.
