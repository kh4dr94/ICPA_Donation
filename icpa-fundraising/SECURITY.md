# Security review — version 0.6.0

Reviewed 3 November 2026. Scope: this plugin's PHP/JavaScript, WordPress request handlers, database access, credential storage and Square integration logic. Validation used disposable WordPress 7.1.4 / PHP 8.3 / MariaDB instances and mocked Square HTTP responses. No live ICPA website, real Square account, production server or third-party plugin was penetration-tested.

## Changes made

| Area | Protection and evidence |
| --- | --- |
| Campaign content / XSS | Story HTML is filtered before WordPress's content pipeline, shortcodes are rendered as literal text, and dynamic block comments are removed. Final story rendering uses the same HTML allowlist. Tests inject scripts, event attributes, unsafe protocols and a registered shortcode probe through the real `the_content` pipeline. |
| CSRF / permissions | Administrative mutations require `manage_options`, POST and WordPress nonces. Checkout and campaign submission require POST/nonces; campaign publication stays administrator-controlled. Reconciliation is now a nonce-protected POST form. Tests exercise subscriber access, forged nonces and GET requests against the actual handlers. |
| Input validation | Campaign/donation identifiers use strict decimal parsing; UUID keys are exact and case-canonicalized. Search length, page offsets, request sizes and provider timestamps are bounded/validated. Malformed arrays, numeric payloads, SQL-like strings and control characters are rejected. |
| SQL injection / privacy | Value-bearing custom SQL uses prepared statements or WordPress insert/update APIs. Donor queries bind the current authenticated account. Tests check malformed identifiers and attempts to select another donor using query parameters. |
| Concurrent abuse | Checkout counters use atomic database increments rather than cache read/modify/write. Eight parallel WordPress workers demonstrate that a three-request test quota cannot be exceeded. Limits are 10 checkout attempts per actor/minute and 5 campaign submissions per account/hour, in addition to the pending-campaign cap. Expired rate metadata has a bounded hourly cleanup task. Only salted actor hashes and counters are stored. |
| Square webhooks | Signatures use constant-time comparison. Environment is derived from the registered webhook route, so JSON/query parameters cannot choose another environment's signing key or API credentials. Payment state is independently retrieved from Square. Amount, currency, location, refund types, payment IDs and strict timestamps are checked before accounting updates. Existing duplicate/out-of-order/refund checks remain in place. |
| Redirects / outbound HTTP | Checkout redirects require HTTPS Square allowlisted hosts and reject userinfo, unusual ports and raw/encoded control characters. API hosts are fixed, redirects are disabled, TLS verification is explicit and responses have a 2 MiB limit. User input cannot select an arbitrary API host. |
| Credentials | New ciphertext uses AES-256-GCM with authenticated environment/field context. Ciphertext edits and swaps fail authentication. Existing v1 ciphertext remains readable; an explicit profile save upgrades readable legacy values without exposing them or requiring re-entry. Secrets are not rendered back into forms, autoloaded or registered with REST Settings. |

## Validation completed

- 93 security regression checks, including concurrent processes against the real WordPress database.
- 29 original button-plugin checks, 89 platform checks, 48 general-donation/profile checks and 55 recurring settings/checkout checks.
- Real HTTP checks for five standalone and two native-theme pages, CSRF/guest restrictions, webhook rejection, GET rejection and oversized checkout rejection.
- Browser checks for donation flows, recurring choices, settings, image loading and seven mobile layouts.

Square responses in the automated suites are fixtures. The checks do not prove real account permissions, merchant configuration, payment completion, subscription signup/renewal/cancellation, or live webhook delivery.

## Deployment responsibilities and limits

Use HTTPS, supported PHP and current WordPress/theme/plugin versions. Restrict administrators, use strong authentication/2FA, protect the WordPress salts/configuration and database backups, and keep errors/secret-bearing HTTP debug logs out of public responses. Credential encryption cannot protect a compromised WordPress server or administrator.

Exclude donation, account and submission pages from caches that ignore the plugin's no-cache headers. Configure a working WordPress/system cron for cleanup. Behind a reverse proxy, have the trusted server restore the client address; the plugin intentionally does not trust arbitrary forwarded-IP headers. Add hosting-level request/body limits and bot protection where needed, including native WordPress registration. Plugin rate limiting cannot prevent traffic from exhausting the server before WordPress executes.

An independent review and actual Square sandbox checkout/webhook/refund/subscription tests remain appropriate before launch. No review guarantees that software is vulnerability-free. No PCI certification or full-site security certification is claimed.

## Behaviour preserved

General donations, optional campaigns and recurring settings remain available. Recurring billing records/cancellations stay in Square; campaign totals cover verified one-time payments. Square locations remain configured per environment; campaign-specific location overrides are a separate feature and were not introduced in this security release.

The original simple donation-link plugin also rejects raw/encoded control characters in URLs. It does not store Square API credentials or process payments itself.

## Version 0.7.0 embedded-card update — 4 November 2026

One-time card entry uses Square's Web Payments SDK iframe. Only a single-use token and selected donation fields reach the nonce-protected, rate-limited HTTPS-only WordPress AJAX handler. The handler binds the amount, currency, campaign and active environment to a local ledger row, creates a Square order and payment with stable idempotency keys, and uses the same strict payment-state validator as signed webhooks. Tokens are not persisted in the plugin database. Existing hosted checkout and recurring giving remain supported.

Additional validation: 27 embedded-card WordPress integration checks with mocked Square responses cover separate public Application IDs, guest checkout, credentials absent from rendered markup, server-set amounts/location, completed-request replays, amount/environment tampering, malformed tokens, HTTPS, network retries, wrong provider amounts, nonce failures and hosted/embedded attempt separation. Browser checks with a mocked SDK cover verification details, retry identity, locked amounts, safe message rendering, completion and SDK load failure. Actual Square iframe rendering, account-specific SCA challenges and live transactions still require Sandbox validation on the installed HTTPS site.

Version 0.7.2 adds server-sourced confirmation amount/reference and mode-specific completion wording, covered by four additional checks included in the count above. Card ticks use Square’s per-field validity events without accessing iframe contents or storing card input. Browser checks cover individual-field invalidation, aggregate validity, contact checks, focused success panels and explicit Sandbox labelling.

## Version 0.8.0 location selection and design update

Administrator-only location discovery uses a POST/nonce-protected action and fixed Square API hosts. Separate Sandbox/Production lists contain only validated IDs and sanitized display labels, with escaped dropdown output. Inactive/wrong-currency choices are disabled and a changed selection is validated against the discovered list. Caches are bound to the access token by a salted HMAC and contain no credentials. Server-controlled locations remain read-only in the dashboard. Each new donation records its location; saves backfill legacy rows with the prior profile location before changing the default. Failed historical-row updates block profile changes. The schema upgrades on plugin version changes.

Fifteen additional WordPress checks (mocked Square) cover environment isolation, safe dropdown labels, ineligible/forged locations, permission checks, token cache invalidation, legacy-location snapshots and reconciliation after changing the default. Existing embedded payment/security checks also pass. Browser checks cover the redesigned responsive form and the minimal page-builder appearance retains the same server-side donation protections.

## Version 0.9.0 optional contribution controls

The server calculates contribution estimates in integer cents, validates a signed/expiring per-form policy, binds it to the current user/campaign/environment/location/currency/rates, and enforces total-charge limits and idempotency consistency. Optional contributions default off and are separate from both base donations and authoritative Square fee reporting. Fee-disabled forms cannot be forged into accepting a contribution. Square order line items, buyer-verification totals and CreatePayment totals use the same computed charge. Actual fee reports are type/currency-checked, attached only to the matching payment/timestamp and never inferred from an estimate. Malformed ancillary fee data is ignored without rejecting an otherwise verified payment. Campaign progress excludes contributions; partial refunds allocate proportionally.

Targeted automated checks cover consent defaults, gross-up/cent rounding, fixed-rate parsing, disabled forms, changed/tampered quotes, maximum totals, amount/choice conflicts, separate line items, actual-vs-estimated fees, fee currency/types and partial/full-refund campaign accounting. Browser checks cover live breakdown/choice updates, required markers and the total passed for Square buyer verification. Square HTTP/SDK are mocked; actual account-specific Sandbox and Production fee reporting still need verification on the installed site.

Version 0.9.1 signs one of three form fee policies (absorb, add-to-donation, donor choice). The server enforces required contributions, rejects omitted/tampered policies, and validates signed per-form overrides. Nine additional PHP checks cover enforced totals, omitted/tampered choices, explicit overrides, mode changes and the three-option admin control. Browser checks exercise the actual radio-button selector, highlight and live explanation.
