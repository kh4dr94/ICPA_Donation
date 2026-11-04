# ICPA fundraising plugins

## Download and install

Version 0.7.0 adds embedded Square card entry for one-time donations when a matching Application ID is configured. Recurring gifts continue to use Square-hosted checkout. It groups campaign management, causes, donations and settings under one **ICPA Donations** WordPress menu.

[Download the ICPA Fundraising plugin ZIP](https://github.com/kh4dr94/ICPA_Donation/raw/refs/heads/main/icpa-fundraising.zip), then upload it in WordPress under **Plugins → Add New → Upload Plugin** and activate it. Upload this plugin ZIP, rather than GitHub's source-code ZIP of the whole repository.

The repository now contains two WordPress plugins:

- **[ICPA Fundraising](icpa-fundraising/README.md)**: the donation plugin with optional campaigns (v0.7), including organiser submissions, donor accounts, Square hosted checkout integration and verified fundraising totals. Upload `icpa-fundraising.zip`. Square credentials and end-to-end sandbox validation are still required before taking live donations.
- **ICPA Square Donations**: the original simple donation-link button described below. Upload `icpa-square-donations.zip` if you only need a button linking to an existing Square payment link.

Open [the interactive platform preview](preview/fundraising-platform.html) in your browser to try general giving, campaign browsing, a sample submission, a demo donor account and the WordPress general, Square and recurring settings screens. No preview action saves real settings or processes a payment. Sample data and simulated actions are labelled throughout. Desktop, campaign and mobile screenshots are in `preview/`.

Version 0.6 includes a [documented security review](icpa-fundraising/SECURITY.md), stricter request/content validation, atomic abuse limits and context-bound credential encryption. It provides settings for Square-hosted weekly/monthly/quarterly/yearly recurring general gifts. Their prices come from validated Square subscription plans; billing and cancellation records remain in Square. The plugin also provides a general custom donation form, configurable amounts and campaign switches, plus separate encrypted Square Sandbox/Production profiles in the WordPress backend. The plugin components use the supplied ICPA screenshots as their style reference: gold/plum colours, rounded typography and white cards. The default theme mode adds campaign functionality inside the existing WordPress page; it does not replace the website header, logo, navigation, banner, bank details, contact form or footer. The preview shows the plugin components only.

Existing `[icpa_donate]` buttons and external Square links are not imported into platform campaign totals. Use the new campaign pages and their checkout forms to track platform donations.

## Original Square link button

A WordPress plugin that adds a donation button linked to Square's hosted checkout. No API key or card details are stored by this plugin.

## Install

1. Upload `icpa-square-donations.zip` under **Plugins → Add New → Upload Plugin**, then activate it. Alternatively copy the `icpa-square-donations` directory to `wp-content/plugins/`.
2. In your Square dashboard, create a payment link for donations and copy the HTTPS link. Configure the amount (including donor-entered amounts if available), currency and any recurring options in Square. Availability depends on your Square account and region.
3. Open **Settings → Square Donations**, paste the link, choose button text and colour, and save.
4. Add a **Shortcode** block to a page or post containing `[icpa_donate]`.

Custom text: `[icpa_donate label="Support our community"]`. The default label and colour apply site-wide. An empty link hides the button. Links must use `square.link`, `squareup.com`, or a single subdomain of `square.site`, over HTTPS. Invalid settings preserve the previous link and show an error.

Checkout opens in the same tab. The plugin does not create payment links, alter donation amounts, track payments, issue tax receipts, or mark donations successful. Manage those in Square. Deactivation preserves settings.

## Requirements and validation

WordPress 6.4+ and PHP 7.4+. No Composer or npm dependencies.

Before accepting live donations, test your own Square link and confirm the recipient, currency, amount options and receipt settings. No live payment is made by the development tests.

Build the upload archive from the repository root:

```sh
zip -r icpa-square-donations.zip icpa-square-donations
```

Integration checks in `tests/integration.php` run against a bootstrapped WordPress install with `wp eval-file tests/integration.php`. They check link validation, settings sanitization, shortcode output, escaping, button contrast, and admin form registration.
