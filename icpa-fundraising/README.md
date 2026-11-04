# ICPA Fundraising — version 0.8.0

An installable WordPress donation plugin with general custom-amount giving and optional fundraising campaigns, organisers and donor accounts. All donations go to **one ICPA Square account**. ICPA approves campaigns and manages distribution; organisers cannot enter a different payment recipient.

This is an initial implementation. WordPress flows and payment logic have automated coverage, but your Square profiles still need real account credentials and end-to-end sandbox testing before live use.

## Card entry on your donation page (0.7.0+)

Enter the **Application ID** for each environment under **ICPA Donations → Square connections**. Copy it from that environment's Square Developer Dashboard Credentials page, not its Application secret. Application IDs and location IDs are public SDK identifiers; access tokens and webhook signature keys remain server-side. The matching Application ID enables embedded card entry for both general and campaign one-time donations, using the existing shortcodes. Blank Application ID retains hosted one-time checkout for backwards compatibility. Recurring giving continues to use Square-hosted checkout.

The donation page must use HTTPS and allow Square's Web Payments SDK and secure card iframe. If a content security policy or optimisation plugin is installed, follow Square's current Web Payments SDK CSP requirements and exclude the Square/payment scripts from delayed loading. The plugin loads the Sandbox SDK (`sandbox.web.squarecdn.com`) or Production SDK (`web.squarecdn.com`) to match the active server environment. No raw card inputs or payment tokens are stored in the WordPress donation ledger. The SDK tokenizes with buyer-verification details before the server creates a Square order/payment and validates its response. Success is shown only for a confirmed completed payment; asynchronous payments remain pending.

Start with Sandbox. Enter a custom amount and test card `4111 1111 1111 1111`, a future expiry, CVV `111` and postcode `2000` in the embedded form. Test successful and declined payments, campaign totals, refunds, webhook delivery and an interrupted connection before enabling Production. These account-specific tests have not been performed by the automated suite. For existing installs, upload the new ZIP and select **Replace current with uploaded**; saved credentials, campaigns and donation records are retained.

During a payment retry, the form locks its amount/email and retains its single-use token and submission key; Square idempotency prevents a repeated request creating another payment. If a network error leaves confirmation uncertain, retry that same attempt or contact ICPA before starting another donation. A declined card requires reloading for a new attempt. The backend rate-limits requests, validates nonces/environment/amount/campaign status, and continues to process signed payment/refund webhooks. No OAuth redirect or Application secret is required.

Version 0.8.0 shows individual green ticks for amount, first name, last name and email, and separate card-number, expiry, CVV and postcode statuses below the Square iframe. Card status comes from Square’s per-field input events; the overall card check requires all four fields to be valid. Invalidated or cleared fields lose their ticks. The selected amount uses contrasting text and a checkmark. A confirmed payment replaces the entry form with a large green confirmation panel, server-confirmed amount and donation reference, and moves keyboard focus to the result. Sandbox says “Test donation completed” and explicitly states that no real money was charged. Pending payments use an amber processing panel and never claim success. This feedback indicates input completeness, not card approval or successful payment. First and last name remain required.

## Design your own page (0.8.0)

Use WordPress blocks or Elementor for your banner, images, columns and text, then insert `[icpa_donation_form]` in a Shortcode block/widget. The plugin keeps your theme's page layout, header and footer. The redesigned default form provides larger controls, numbered giving/details/payment sections, responsive name columns and the existing validation/confirmation states.

For a form that fits directly inside your own section/card without another border, background or shadow, use:

```text
[icpa_donation_form appearance="minimal"]
```

Set colours and the donation heading under **ICPA Donations → Settings**. Advanced theme styling can target `.icpa-general-widget`, `.icpa-payment-entry` and `.icpa-form-step`. Square's secure card iframe retains its own layout; the plugin never replaces it with ordinary WordPress card inputs. The plugin does not change your page builder content or other plugins' dashboard notices.

## Select Square locations by name (0.8.0)

1. Under **ICPA Donations → Square connections**, save the matching environment's access token.
2. Click **Load Sandbox locations** or **Load Production locations** below the profiles. Save profile edits before loading.
3. Select the named location from that profile's **Square location** dropdown and save profiles.
4. Run its connection test and test a payment. Refresh the location list after adding/changing Square locations.

All returned locations are listed; inactive locations or locations using a different site currency are disabled. Discovery is administrator-only, nonce-protected and read-only against Square. Location names/IDs are cached independently for each environment and invalidated when the access token changes. The manual legacy field on general Settings has been replaced with a link to these profile selectors. Existing saved locations remain selected even before discovery; advanced server-configured locations remain controlled on the server. Changing the selected location applies to new donation attempts. Each new one-time donation stores its location so later payment/refund reconciliation continues using its original location; previously stored rows without a location are assigned the old profile location before an administrator changes profiles. Credentials must still belong to the same receiving ICPA Square account for historical payments to be accessible. In-progress attempts must be reloaded if the default location changes.

## Included

- Configurable Square-hosted recurring general gifts: weekly, monthly, quarterly or yearly fixed-price plans, with separate Sandbox/Production selections. Renewals and cancellations remain managed in Square.
- A general donation shortcode with suggested amounts, a custom amount, guest checkout and configurable minimum/maximum donations.
- Independent switches for general donations and campaigns; use either or both.
- Separate encrypted Sandbox/Production credentials, location IDs, read-only connection tests and per-environment webhook URLs/status under **ICPA Donations** in WordPress.
- Searchable campaign directory, cause filters, stories, goals and progress bars.
- Campaign cover images through WordPress Featured Images, with bundled illustration fallbacks.
- Signed-in organiser submissions, limited to five awaiting review. Every submission is pending until an ICPA administrator publishes it. Administrators manage later edits and closure.
- One-time donations with suggested or custom amounts and embedded Square card entry when an Application ID is configured; hosted checkout remains available when it is blank.
- WordPress donor/organiser accounts, native login, registration and password reset. Donor history is private to the signed-in account. Guest giving is supported; guest donations are not automatically attached to accounts by email.
- Server-created checkout amounts, idempotent checkout submissions, signed webhooks and independent Square payment verification. A return from checkout never counts as payment confirmation.
- Verified progress totals less refunds, separate sandbox/production totals, safeguards for duplicate/out-of-order events and failed payment retries. Processing fees are not deducted from displayed fundraising totals.
- Administrator donation ledger and manual payment refresh for reconciliation.
- Responsive layouts, native WordPress theme integration and an optional standalone layout. Theme mode keeps the existing website header/footer, inherits its typography and uses its available WordPress/Elementor colour variables.
- Appearance settings for brand colours, a Media Library banner and donation page wording. Standalone navigation uses the WordPress custom logo when set.

## Install and set up WordPress

Requires WordPress 6.4+, PHP 7.4+, and a supported WordPress MySQL/MariaDB database. No Composer or npm packages are needed by the plugin.

1. Upload `icpa-fundraising.zip` in **Plugins → Add New → Upload Plugin** and activate it.
2. Create the published pages you need (you can reuse existing pages):

   | Page | Shortcode |
   | --- | --- |
   | General donations | `[icpa_donation_form]` |
   | Discover campaigns | `[icpa_campaigns]` |
   | Start a fundraiser | `[icpa_start_campaign]` |
   | My account | `[icpa_account]` |

3. Keep the default **Use my website theme** layout to embed the platform in your existing website. Use the ordinary theme page template or your page builder’s full-width content template. Campaign pages also keep the active theme. For the plugin’s separate layout, choose **Standalone fundraising pages** in Platform settings and select **ICPA Fundraising (full width)** for the shortcode pages.
4. In **ICPA Donations → Settings → Website appearance**, choose your layout, set any exact brand colour overrides, select an existing banner image and edit the introduction. Blank colour fields inherit available theme variables; otherwise the plugin uses gold/plum colours approximated from the supplied ICPA screenshots. Existing site fonts are inherited in theme mode; standalone mode uses bundled Poppins (SIL Open Font License in assets/fonts/OFL.txt). Then select those page assignments, your currency and the Square environment/location. Default currency is AUD; choose the actual ICPA Square location currency before creating a checkout. Currency is locked after the first checkout, including test checkouts. One site supports one currency.
5. To allow self-service accounts, enable **Settings → General → Membership → Anyone can register** and set the default role to **Subscriber**. Configure reliable WordPress transactional email so account setup and password resets work. Existing accounts work without enabling registration.
6. Exclude general donation, campaign checkout, account and submission pages from any CDN/page cache. The plugin sends no-cache headers and `DONOTCACHEPAGE` for these pages, but external caches must honour them. Campaign pages with donation nonces should also be excluded or refreshed before WordPress nonces expire.
7. Add a campaign as administrator, or submit one from the frontend. Review its story, goal, cause, recipient and featured image, then publish it. A goal is required for checkout. Set an end date or close it manually when needed.

Activation does not create sample campaigns, seed donation totals, enable user registration, or create pages automatically. Deactivation retains campaign, donation and configuration data.

## Add to the existing ICPA donation page

This is an installable WordPress plugin, not a replacement website or theme. Keep **Use my website theme** selected. Add a Shortcode block (or Elementor Shortcode widget) where the general donation form should appear on the existing Donations and Charities page:

```text
[icpa_donation_form]
```

To keep campaigns as an option, also enable campaigns in **ICPA Donations → Settings**, then add this shortcode on the same page or another existing page:

```text
[icpa_campaigns faq="no"]
```

The website retains its real logo, menus, banner, existing donation/bank information, photos, contact form and footer. The shortcodes add donation forms or campaign cards, filtering and fundraising links. It does not create or overwrite a page. The `faq="no"` attribute avoids adding another FAQ when the website already has one.

The other shortcodes, `[icpa_start_campaign]` and `[icpa_account]`, provide organiser and donor functionality in ordinary WordPress pages. Select those page IDs in Platform settings so plugin navigation works.

In theme mode, the plugin's optional hero and general introduction are hidden by default. They can be explicitly enabled with `hero="yes" intro="yes"`; the separate standalone layout remains optional for testing or sites without an existing page design. No theme files are edited. Colours and font identity are based on screenshots, not an inspection of the original CSS. Use the existing Media Library for real campaign images.

## Donation options and backend settings

Open **ICPA Donations → Settings** to choose whether to offer general donations, campaigns or both. Set the heading, suggested amounts (up to six), minimum/maximum amounts, currency, active Square environment and page assignments. The same amount rules apply to campaign checkouts. Disabled options are enforced on the server, not just hidden in the page.

General donations are stored as **General donation** in the donation ledger and signed-in donor history. They never contribute to a campaign's progress. Guests can give without an account; accounts remain optional for viewing donation history. To embed a donation form for one approved campaign, use `[icpa_donation_form campaign="123"]`, substituting its real WordPress campaign ID.

## Connect Square

1. Go to **ICPA Donations → Square connections**. Save the Sandbox Application ID, access token, location ID and webhook signature key. Save the Production profile separately when ready. Saving profiles does not switch checkout mode.
2. In **ICPA Donations → Settings**, choose the active checkout environment and currency. The currency must match the Square location. Start with Sandbox. One site uses one currency; it is locked after the first checkout, including test checkouts.
3. Register each environment's exact webhook URL shown in its profile. These use the WordPress REST routes `icpa/v1/square/webhook/sandbox` and `icpa/v1/square/webhook/production`. Copy the URL exactly, including any query string when pretty permalinks are off.
4. Subscribe both environments to `payment.created`, `payment.updated`, `refund.created` and `refund.updated`. Callbacks are authenticated and processed using their own profile, even when the other environment is active for new checkouts. Sandbox records never count in production totals.
5. Use **Test Sandbox connection** or **Test Production connection**. These read-only checks validate token access, location status and currency. They do not charge a card or prove webhook delivery. The profile shows the last successfully verified payment notification timestamp separately.
6. Complete an end-to-end sandbox donation, confirm the donation record and any campaign progress, replay a notification, and test a refund. Only then switch to Production with the appropriate ICPA credentials.

Credentials are never echoed back into the admin form. Leave a secret field blank to keep its value, or tick its remove checkbox. Saved secrets use authenticated AES-256-GCM encryption with a key derived from WordPress authentication salts; the option is not autoloaded or exposed through the REST Settings API. PHP OpenSSL is required for storing credentials in the backend. Rotating WordPress authentication salts makes stored credentials unreadable until re-entered. Protect the site's configuration and database backups; encryption does not protect against a compromised WordPress server or administrator.

Use HTTPS for the WordPress site, administration and publicly reachable webhook endpoint. WordPress security/CDN rules must allow Square webhook POSTs. API hosts are `connect.squareupsandbox.com` and `connect.squareup.com`. Required Square application/account permissions cover creating payments and orders, creating online checkout links, and retrieving locations, orders and payments. The plugin pins Square API version `2025-01-23` and keeps TLS verification enabled.

Advanced server configuration overrides saved profile credentials using constants or environment variables:

- `ICPA_SQUARE_SANDBOX_ACCESS_TOKEN`, `ICPA_SQUARE_SANDBOX_WEBHOOK_SIGNATURE_KEY`, `ICPA_SQUARE_SANDBOX_LOCATION_ID`
- `ICPA_SQUARE_PRODUCTION_ACCESS_TOKEN`, `ICPA_SQUARE_PRODUCTION_WEBHOOK_SIGNATURE_KEY`, `ICPA_SQUARE_PRODUCTION_LOCATION_ID`

Legacy `ICPA_SQUARE_ACCESS_TOKEN`, `ICPA_SQUARE_WEBHOOK_SIGNATURE_KEY`, the old location field and the legacy webhook route remain supported for the currently selected environment only. Use explicit profiles and their separate webhook URLs for simultaneous sandbox/production support. Never put credentials into shortcode attributes, campaign content, source files or the standalone preview.

The automated development tests mock Square HTTP responses. They do not validate your actual account credentials or perform a real Square checkout. Square supplies payment receipts; configure those in Square. Tax receipts, organiser payouts, platform fees, marketplace OAuth and synchronising subscription renewals into WordPress remain outside this version.

## Configure recurring donation options

Go to **ICPA Donations → Recurring giving**. Recurring options are disabled by default and apply to **general donations only**. Campaign checkouts remain one-time, so recurring renewals never misleadingly increase campaign progress.

1. In Square, create the fixed-price subscription plans you want to offer in each environment. This version accepts one indefinite phase with static pricing, no trial, discounts, proration or billing anchor. Supported intervals are weekly, monthly, quarterly and annual. Plan currency and amount must match the site's currency and donation limits.
2. Configure the corresponding Square profiles in WordPress. The token needs Catalog read access in addition to the Checkout permissions already required for donation links.
3. Click **Load Sandbox plans**. Eligible plan variations appear in the interval dropdowns. Use **Load next page** if your catalog has more than 100 results.
4. Select the Square plan for an interval and click **Validate & create checkout**. This reads the plan again and creates or reuses a Square-hosted subscription payment link. It does not charge anyone or create a customer's subscription.
5. Enable recurring general donations and choose which intervals donors may see. Keep **Offer one-time general donations with custom amounts** selected to show both, or turn it off to offer configured recurring gifts only. Repeat plan setup separately for Production when ready.
6. Test the real sandbox subscription signup, first charge, renewal behaviour and cancellation in Square before enabling production recurring giving. Developer tests simulate Square responses; they do not establish that an actual merchant account supports or correctly configures those operations.

The existing `[icpa_donation_form]` shortcode displays the configured choices automatically. Each recurring choice discloses its fixed amount and interval before opening Square checkout, where the donor authorises billing. Donors cannot enter an arbitrary recurring amount in this version; custom amounts remain available for one-time gifts. The plugin re-fetches the plan before redirecting and blocks changed prices/versions until an administrator revalidates the option. Changing credentials/location/currency invalidates existing configured links in the plugin until revalidation.

**Recurring billing records, receipts, cancellation and subscriber management are handled in Square.** They are not synchronised to the WordPress donor history, one-time ledger or campaign totals. The frontend and admin screens explain this distinction. Contact ICPA to change or cancel a recurring gift; ICPA manages it in Square. Disabling a setting removes the option from this plugin but does not cancel existing subscriptions or deactivate previously shared Square checkout URLs; manage those in Square.

## Donation records and recovery

Only a verified `COMPLETED` Square payment contributes to totals. Totals subtract the current refunded amount returned by Square. Donation attempts that have not been confirmed remain pending; they may be abandoned. Switching between sandbox and production changes which environment's records are shown, without deleting either.

The admin **Donations** page includes a **Refresh from Square** action for a stored order/payment. Use it to check a suspected missed webhook; otherwise inspect the payment in Square and replay its notification. `linked` means an unpaid hosted-checkout template has a separate paid order; it is hidden from donor history. Reused hosted links are matched by the server-assigned payment note. Their actual, distinct completed payments are recorded individually.

The plugin stores WordPress account IDs, campaign IDs, amount/currency, Square order/payment references and payment state. It does not store card details or publish donor identities. Add the platform's data use and retention practices to your site's privacy policy. Back up the WordPress database, and restrict campaign/ledger administration to trusted administrators.

## Security

See [SECURITY.md](SECURITY.md) for the v0.6 review scope, hardening changes and verification evidence. New saved secrets are cryptographically bound to their environment and field. Existing encrypted credentials remain readable; saving their profile upgrades legacy ciphertext without changing the underlying value. Campaign stories preserve supported static HTML while treating shortcodes as literal text and excluding dynamic blocks.

## Preview and development

The repository's `preview/fundraising-platform.html` is a standalone interactive demo built from WordPress-rendered screens. It contains sample campaigns, synthetic totals and a demo account. Its donation and submission actions are simulated in the browser. It neither changes WordPress nor contacts Square.

Run the existing integration suite in a **disposable** WordPress installation:

```sh
php /test/platform-integration.php
```

The test file expects WordPress at `/var/www/html`, activates the plugin, creates fixture accounts/campaigns and clears the test donation table. Never run it against production. See `tests/run-platform.sh` for an isolated Docker runner. The demo fixture is separately guarded and excluded from the upload ZIP.

Build from the repository root:

```sh
zip -r icpa-fundraising.zip icpa-fundraising
```
