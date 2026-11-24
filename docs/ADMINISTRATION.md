# Commerce NovaPay administration

This guide covers the environment-specific work required after the module has
been installed. Access to payment gateways requires the Commerce permission
`administer commerce_payment_gateway`.

## 1. Prepare private storage

Set Drupal's `file_private_path` to an absolute directory outside the web root.
It must be writable by the PHP process and persistent across application
restarts and deployments. For example, if `DRUPAL_ROOT` points to the public
document root:

```php
$settings['file_private_path'] = dirname(DRUPAL_ROOT) . '/private';
```

Do not use `public://`, a temporary directory, or a release directory that is
replaced on deployment. Verify the directory's ownership and permissions from
the same runtime user that executes Drupal before creating a live gateway.

## 2. Configure the payment-phone source

NovaPay can prefill checkout only from a configurable field of type
`Telephone` that has been explicitly designated for the integration.

For every Commerce customer profile type used by the store:

1. Open **Commerce > Configuration > Orders > Customer profiles**.
2. Open **Manage fields** for the profile type.
3. Add a field of type `Telephone`, or edit an existing field whose Drupal
   field type is already `Telephone`. Plain-text and numeric fields cannot be
   selected as the NovaPay phone source.
4. On the Telephone field edit form, select the module-provided checkbox
   **Use this field as the NovaPay payment phone** and save. Existing Telephone
   fields also receive this checkbox after the module is enabled.
5. Ensure the field is present on the checkout form and collects a Ukrainian
   number. Local and international Ukrainian formats are normalized to E.164.

The resolver checks a marked field on the order, then billing profile, then
customer account. Gateway validation specifically checks every Commerce
customer profile type and prevents saving until each has a marked Telephone
field. Merely having an unmarked telephone or plain-text field is insufficient.

## 3. Create a gateway

Open **Commerce > Configuration > Payment > Payment gateways**
(`/admin/commerce/config/payment-gateways`) and select **Add payment gateway**.

1. Enter a unique name and machine ID.
2. Select the **NovaPay** plugin.
3. Choose the stores and conditions where it may appear.
4. Optionally enable the NovaPay logo.
5. Configure the environment-local fields described below.
6. Enable and save the gateway.

To add another NovaPay gateway, repeat the process. Use a separate gateway for
another merchant account, store, transaction mode, or set of conditions. The
runtime profile is isolated by gateway UUID, so imported or duplicated gateway
configuration has to be initialized independently on every environment.

## 4. Configure test mode

Choose **Test** for local and sandbox validation. The module fixes the endpoint
to `https://api-qecom.novapay.ua`. By default it uses Merchant ID `2` and the
official sandbox fixtures packaged in `resources/test`.

For merchant-specific sandbox credentials, select **Use custom test
credentials**.
This reveals **Test Merchant ID** and the merchant private/NovaPay public key
uploads. Enter the sandbox ID and upload both unencrypted RSA PEM files
together.
The public key must be NovaPay's verification key. Save and reopen to confirm
the ID and installed-key status. Blank uploads retain installed keys;
replacement
requires both files. Unchecking restores bundled credentials while retaining the
custom ID/files for reuse. Live credentials remain separate. All values are
stored per gateway in private environment-local storage, outside config sync.

After any remote NovaPay payment exists, changing credentials/source/API mode or
uploading keys is blocked. Create a new gateway for new credentials; retain the
old gateway for callbacks and operations on existing payments.

Choose a transaction mode:

- **Direct** creates an immediate charge session;
- **Hold** creates an authorization that must later be captured or voided.

Set **Success redirect delay** to the number of seconds the NovaPay success
page waits before returning to Drupal. `0` omits automatic return timing. This
affects only sessions created after the change.

The public NovaPay sandbox may emit the legacy v1 callback signed with
RSA-SHA1. The module accepts that combination only with bundled Test
credentials.
Custom Test credentials accept v2 with RSA-SHA256 or sandbox RSA-SHA1.
Live accepts RSA-SHA256 only. It also
supports v2/RSA-SHA256 test fixtures.

## 5. Configure live mode and keys

Obtain production credentials through the merchant's NovaPay acquiring
onboarding:

- NovaPay assigns the **Merchant ID**;
- an authorized merchant user starts key generation in the NovaPay Acquiring3
  admin panel; NovaPay registers the matching merchant public key and downloads
  the **private key** to the user's device as a `.pem` file;
- NovaPay supplies its current **public verification key** through official
  acquiring documentation or the onboarding contact.

Verify the NovaPay public-key fingerprint through an approved, independent
channel before production use. Do not copy a key from an old ticket, chat, log,
or source repository.

In the gateway form:

1. Select **Live**.
2. Enter the Merchant ID exactly as NovaPay supplied it.
3. Upload the merchant private PEM and NovaPay public PEM in the same save.
4. Choose Direct or Hold and save.
5. Reopen the form and confirm **Keys installed**. Key contents are never
   displayed.

Leaving both upload fields empty preserves installed keys. Supplying only one
file is rejected. Rotation also requires both files in a single save and is
atomic. The merchant private key and NovaPay public key have different owners,
so they must not be tested as halves of one key pair.

Every local, demo, UAT, and production environment needs its own runtime
profile and appropriate credentials. A Drupal configuration import creates the
gateway entity but does not provision any runtime value or PEM file.

## 6. Recipient identifier

**Recipient identifier** is an optional EDRPOU or tax identifier for a payment
recipient different from the merchant. Leave it empty for the standard
single-merchant flow. Coordinate its value and supported operations with
NovaPay; item-level partial capture/refund scenarios may require it.

## 7. Callback and public URL

The gateway displays a read-only callback URL generated by Drupal Commerce,
normally `/payment/notify/{payment_gateway_id}`. The module includes it when
creating a NovaPay session; there is no separate editable callback setting.

For a development site that is not publicly reachable:

1. expose the site through a stable HTTPS tunnel such as ngrok;
2. configure Drupal so absolute URLs use that public host and HTTPS;
3. reopen the gateway and confirm its displayed callback URL uses the public
   host;
4. ensure the route is reachable without HTTP authentication, IP filtering, or
   a proxy redirect that changes the request body.

NovaPay signs the exact raw body. A proxy, WAF, or middleware must not rewrite
JSON before Drupal verifies it.

## 8. Operate payments

Manage a payment from its Commerce order or payment administration screen.
Operations are offered only when valid for the current payment state.

- **Confirm hold** settles a held amount. Partial capture is supported when the
  NovaPay operation and order data permit it.
- **Refund** releases an authorization before capture. The same action opens
  item refunds for captured payments; the final button is **Refund via NovaPay**.
- **Refund** uses item quantities directly; there is no mode selector or
  arbitrary-amount input. Leave quantities blank/zero, or select all available
  quantities, for a full remaining-balance refund. Smaller valid selections
  request partial refunds for eligible payments.
- After a **partial hold capture**, only a full remaining-balance refund is
  available. Quantity inputs are omitted and partial requests are rejected on
  the server. The warning uses a bordered amber panel with a clear heading.
- **Check NovaPay payment status** performs read-only reconciliation after a
  missing or uncertain direct, hold, capture, or void result.
- **Check refund status** reconciles the one pending refund without sending a
  second refund request.

Wait for a signed callback or a successful status check before treating an
operation as financially final. Never repeat a write operation merely because
the browser timed out.

The public sandbox does not reliably execute refunds. An expected sandbox
`RefundError` is not evidence that production refunds are broken. Validate a
successful refund in an approved UAT/production account with an eligible recent
paid order and the conditions communicated by NovaPay.

Refund validation:

- Quantities are optional. Blank and zero values mean no individual selection;
  if all fields are blank/zero, the whole remaining paid balance is refunded.
- Positive quantities must use the configured quantity step and cannot exceed
  the purchased quantity minus quantities already refunded for that payment.
- Negative, malformed and non-string quantities, unknown items and malformed
  rows are rejected. Invalid data must never become a full-refund request.
- Partial selections must have a positive total with at most two decimal places
  and cannot exceed the actual captured balance minus confirmed refunds.
- Selecting all remaining quantities is a full-refund intent, including when
  item totals differ from the captured balance because of a partial capture or
  paid allocation. The amount refunded is always the remaining paid balance.
  For example, a hold of 1,000 UAH is partially captured for 600 UAH:
  the refund form offers only a full refund of 600 UAH, never 1,000 UAH.

A partial refund sends the active NovaPay operation ID and exact refund amount.
Full refunds omit operations. The UI total shows the remaining paid balance for
blank/all-item full intent and the calculated item sum for partial intent.
Financial totals and confirmed quantities change only after authoritative
postback/status evidence. When evidence lacks the cumulative refunded amount,
the partial intent stays pending; use **Check refund status** to reconcile it.

On upgrade run database updates before reopening refund operations. Update
11007 preserves historical item-ledger rows and unfinished legacy refund
intents, adds a nullable item identifier for monetary rows (quantity zero), and
keeps a non-null unique selection key to prevent duplicate monetary rows.

Capture provenance is stored per payment in the existing order data map, without
new fields or tables. Confirmed partial captures are marked TRUE; confirmed full
captures are explicitly marked FALSE. Direct payments keep item refunds.
For older held payments without provenance, captured amount below the current
order total conservatively enables full-only refunds. This cannot reconstruct
historical authorization after order-total changes and may restrict an older
split-payment/full-capture scenario. New captures use exact provenance.

## 9. Deploy and rotate safely

Export normal gateway configuration with Drupal, but provision runtime files
separately after import. Under the private scheme they are stored as:

```text
commerce_novapay/{gateway_uuid}/settings.json
commerce_novapay/{gateway_uuid}/private.pem
commerce_novapay/{gateway_uuid}/public.pem
```

Exclude these paths from Git, deployment packages, public file synchronization,
database dumps shared outside the approved environment, and configuration
sync. Restrict filesystem access to the PHP runtime and authorized operators.

After deployment or rotation, create a new low-value UAH session, confirm its
signed v2 callback, and check that no secret material appears in logs. Deleting
the gateway removes its local runtime directory; back up credentials first if
retention policy requires recovery.

## 10. Logging policy

Keep **Enable detailed logging** off unless sanitized diagnostic metadata is
needed. Even when enabled, never log or paste into tickets:

- private/public PEM contents or fingerprints not approved for disclosure;
- signatures, authorization tokens, cookies, or secret headers;
- raw callback/request/response bodies;
- card PAN, CVV, or other payment credentials;
- complete phone numbers, names, addresses, email, or other personal data;
- database rows or screenshots containing customer/payment data.

Use bounded identifiers and timestamps when correlating an incident.


## Capture transaction IDs

Partial capture creates a new NovaPay transaction. Once a pending capture is
confirmed by a verified callback or read-only payment status check, its ID
replaces the existing `novapay_operation_id`. Refund-status checks use this
active transaction. After partial capture, only a full remaining-balance refund
is available; its request omits operations.

If a successful signed partial-capture callback omits the new transaction ID
or repeats the old ID, the module performs one preliminary read-only status
request under the existing payment-session lock. It verifies the same session,
a paid/hold_confirmed status and one distinct new transaction ID before applying
the callback. It never repeats capture or refund. A failed or inconclusive
lookup leaves the capture pending and the callback retryable; a later NovaPay
retry or manual status check can reconcile it. The payment is not confirmed
using an old operation ID. A callback already carrying valid evidence and
identical processed duplicates do not trigger a status request.


Missing or ambiguous transaction evidence keeps capture pending. Run **Check
NovaPay payment status** to reconcile it; the action sends no capture or refund
request. If evidence remains ambiguous, resolve it with NovaPay rather than
choosing an arbitrary operation or substituting an order ID.


## Order status history

The **Order activity** section on order details uses the standard Commerce
Log submodule. It records order workflow transitions and payment state changes,
including NovaPay callback and status reconciliation changes.
Enable the optional `commerce_log` submodule explicitly when order history is
needed. Commerce NovaPay does not enable it automatically. No custom NovaPay
history table is created. Past changes made before logging was enabled are
not backfilled.

If history is missing, verify Commerce Log is enabled, the `commerce_activity`
View exists and relevant log templates are enabled in Commerce Log settings.
A custom order template must render the standard activity section. Access
continues to follow the source order permissions. API detailed logging does not
control this order history.

## Specification payment status

The module displays the fixed NovaPay payment status in order details.
To show the same status in the orders list, edit the `commerce_orders` View
and add the Order field "NovaPay payment status" without a payment relationship.
The field position, label and visibility are managed in the Views editor.
The module does not inject columns into existing Views. Other order Views can
use the same field. There is no configuration mapper, new storage, or change
to Commerce financial/order states.

The Ukrainian labels are: created = "В обробці", processing = "Очікування
оплати", holded = "На утриманні", paid/hold_confirmed = "Виконано", failed =
"Не вдалося", full refund and released authorization = "Повернено", expired =
"Прострочено". A partial refund remains "Виконано". English uses "Paid" for
direct payments and partial refunds, "Completed" for confirmed holds, and
"Refunded" for both full refunds and released authorizations.

For multiple payment attempts, the newest completed, partially refunded,
or
held payment takes precedence; otherwise the newest attempt is used.
Selection
includes other providers: if the selected payment belongs to another
gateway,
the NovaPay status is empty. This is a single-payment display, not an
aggregate
for orders split across several payments. Empty statuses are hidden in
order
details. Existing standard Commerce state fields retain their own
meaning.

Deployment: import `translations/commerce_novapay.uk.po` into Ukrainian
interface translations and rebuild caches. Status translations use the
"NovaPay payment
status" context so specification wording does not replace labels from
other
modules.
No database update or configuration import is required.
