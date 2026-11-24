# Commerce NovaPay troubleshooting

Start with the gateway mode, payment state, timestamp, gateway machine ID, and
a sanitized Commerce payment identifier. Do not request or attach raw payloads,
signatures, keys, full phone numbers, or cardholder data.

## Gateway cannot be saved

**Message about local settings or writable private storage**

- confirm `file_private_path` is configured outside the web root;
- confirm PHP can create and atomically replace files there;
- ensure the directory persists on the application host or platform storage;
- do not work around the check with `public://` or permissive public storage.

**Message about a missing or unmarked Telephone field**

- add a field whose Drupal type is `Telephone` to every Commerce customer
  profile type;
- edit each field and select **Use this field as the NovaPay payment phone**;
- clear caches after importing field configuration.

**Live key upload is rejected**

- upload both files together;
- ensure they are valid PEM encodings of the expected private/public key types;
- confirm the private key is the merchant key registered with NovaPay and the
  public key is NovaPay's current production verification key;
- do not expect those two files to match as a key pair;
- verify file size, line endings, storage permissions, and Merchant ID without
  printing key contents.

## NovaPay is absent at checkout

- ensure the gateway and its payment method are enabled;
- confirm the order store and gateway store selection match;
- review gateway conditions and checkout flow placement;
- confirm the order balance is positive and currency is `UAH`;
- enter a valid Ukrainian phone in the designated field;
- reopen the gateway after config import and save its local runtime profile.

## Payment session cannot be created

- verify the selected API mode and endpoint are appropriate for the account;
- in Test mode, do not supply live credentials; the packaged Merchant ID `2`
  and fixtures are used automatically;
- in Live mode, confirm Merchant ID and both key files are installed locally;
- check outbound HTTPS connectivity and system time;
- inspect only sanitized Drupal log metadata for validation/API category and
  timestamp;
- verify the order uses UAH and has a non-zero balance.

## Customer does not return to the site

The return redirect is a usability feature, not financial confirmation.
Confirm that the generated success/failure URLs use the external HTTPS host.
Adjust **Success redirect delay** for newly created sessions if needed. A value
of `0` tells NovaPay not to use an automatic return timeout.

Do not mark a payment complete from the browser return. Wait for a signed
callback or use the read-only payment status check.

## Callback is missing

- confirm the displayed callback URL is public HTTPS and reaches Drupal;
- check DNS/tunnel, reverse proxy, WAF, maintenance mode, and HTTP
  authentication;
- ensure the proxy preserves the exact request body;
- confirm the gateway machine ID in the route exists and the gateway runtime
  profile is configured on this environment;
- use **Check NovaPay payment status** rather than repeating the payment,
  capture, or void request.

For local development restart the tunnel if its URL changed, configure Drupal's
external base URL, clear caches, and create a new session. Existing sessions
retain the callback URL supplied when they were created.

## Callback signature is rejected

- confirm Test and Live credentials were not crossed;
- verify the current NovaPay public key and its trusted fingerprint;
- ensure no middleware reformatted or re-encoded the body;
- verify host time and that the callback reached the intended gateway;
- remember that production accepts v2/RSA-SHA256 only. Legacy v1/RSA-SHA1 is
  accepted solely for the public sandbox in Test mode.

Do not weaken signature verification or enable the sandbox compatibility path
in Live mode.

## State remains pending after capture, void, or payment

An HTTP timeout is an unknown result, not a failed transaction. Use **Check
NovaPay payment status**. It queries NovaPay without issuing another financial
operation and applies only a valid supported transition.

If state still differs, compare sanitized identifiers and timestamps in Drupal
and NovaPay support. Do not edit the Commerce payment state manually until the
financial state is independently confirmed.

## Refund fails or remains pending

The public sandbox may return `RefundError` or omit successful refund behavior.
Use an approved live/UAT account for an end-to-end success check.

Refund and hold-cancellation errors use the approved 22-row refund catalog
(`Помилки refund 1.xlsx`, sheet `Аркуш 1`, A3:D24, TASK-024). The module maps
known codes and HTTP 500 to local English/Ukrainian messages; raw API error
and description text is never displayed. Unknown responses retain the generic
safe message. Capture and checkout do not use refund-specific messages.

On an existing Ukrainian site, import the module PO with Drush:

```bash
drush locale:import uk \
  modules/custom/commerce_novapay/translations/commerce_novapay.uk.po \
  --override=not-customized -y
drush cr
```

Customized translations are preserved; review them separately if a new message
is still displayed in English. When an error also says to wait for postback,
that warning takes precedence over the catalog's suggestion to try later:
confirm the remote result before retrying. Pending markers and financial state
follow the existing reconciliation rules.

For a pending refund, use **Check refund status**. It reconciles NovaPay's
cumulative refund evidence without sending a second refund POST. Do not submit
the refund form again. Confirm that selected item quantities do not exceed the
remaining refundable ledger and that the payment still has a refundable
balance.

## Configuration imported but credentials disappeared

This is expected. Runtime mode, Merchant ID, operational options, and PEM files
are intentionally excluded from Drupal configuration. Open each imported
gateway on the target environment and initialize its local profile. Provision
separate local/demo/UAT/production credentials; never copy a production private
directory into a lower environment.

## Logs and escalation evidence

Provide:

- environment and gateway machine ID;
- UTC timestamp and operation type;
- Commerce order/payment identifiers approved for support use;
- mode, transaction mode, HTTP status, bounded error category;
- steps taken and the result of a read-only status check.

Never provide PEM data, signatures, raw JSON, headers/tokens, PAN/CVV, full
telephone numbers, customer personal data, or unredacted screenshots.


## Custom sandbox credentials

- If the custom fields are hidden, select **Test** and enable **Use custom test
  credentials**. The checkbox does not change the API endpoint.
- Initial activation requires the test Merchant ID and both RSA PEM files.
  Replace keys together; leave both uploads empty to keep installed files.
- Check that the public file belongs to NovaPay and both credentials were issued
  for the sandbox. Custom Test callbacks require v2 and accept RSA-SHA256
  or sandbox RSA-SHA1.
  Live remains RSA-SHA256 only.
- Missing/invalid custom files disable this credential source; there is no
  automatic fallback. Restore the files or select bundled mode before payments.
- If credential changes are blocked because a remote payment exists, use a new
  gateway. Existing sessions must continue using their original merchant/keys.
- Custom test PEM files are `test-private.pem` and `test-public.pem` within the
  gateway UUID private directory. They must never overwrite live files.


## Partial refund after partial capture

A partial capture can replace the active transaction. The existing
`novapay_operation_id` must be updated from the confirmed capture callback or
read-only status response before the capture is completed locally. Missing or
ambiguous evidence keeps capture pending; use **Check NovaPay payment status**.
Never use external_id or choose an arbitrary operation.

`RefundTryLaterError` alone does not prove an incorrect transaction ID. In the
observed direct-payment request, payment used `use_hold=false`, so no partial capture
preceded that rejection. No financial request was automatically retried.


## Missing order status history

Enable the optional Commerce Log submodule (`drush en commerce_log -y`) and
rebuild caches. Confirm the `commerce_activity` View is enabled, its relevant
log templates are not disabled, and the admin order template renders activity.
The standard log records future order/payment changes independently of NovaPay
API detailed logging. It cannot reconstruct events from before enablement.

The installed Commerce Log version has an observed placement-event limitation:
a nested order save can cause the original state label to appear as the target
state (for example Completed to Completed). This is distinct from missing
history. The integration uses the standard subscriber without patching contrib;
payment activity and other transitions remain separately recorded.
