# Custom sandbox credential acceptance

Use generated RSA keys and mocked HTTP for automated checks. Never put customer
credentials in fixtures or screenshots, and never call live NovaPay API in CI.

1. Open a new NovaPay gateway in Test mode. Confirm the custom checkbox is off
   and the custom ID/uploads are hidden. Select it and confirm all three fields
   appear. Switch to Live and confirm the checkbox and custom fields disappear.
2. Submit custom mode without ID/keys, with one file, malformed PEM, a non-RSA
   key, or an encrypted private key. Confirm a safe error and no API request.
3. Enter a sandbox ID and independently generated merchant-private and NovaPay-
   public PEM files. Save/reopen; confirm the ID, checkbox, and installed
status,
   without displaying key contents. Save blank uploads and verify retention.
4. Before creating any remote session, disable/save/re-enable the option and
   switch Test/Live. Verify independent values/files survive; replacing one file
   fails and leaves the prior settings/files usable.
5. With mock API, verify custom test requests contain the selected Merchant ID,
   verify signatures against its merchant public key, and assert the fixed
   sandbox endpoint. Sign v2 callbacks with the corresponding mock NovaPay
   private key using SHA-256 and SHA-1; verify Test processing. Reject bad
   signatures, custom v1 payloads, and all Live SHA-1 signatures.
6. Verify bundled Test mode still uses ID 2 and supports its existing documented
   legacy callback path. Confirm live credentials are never selected in Test.
7. Create a remote payment and try changing merchant/source/mode or uploading
   keys. Confirm rejection, unchanged stored files, and continued use of
original
   credentials for existing payment operations. Use a new gateway for new keys.
8. Confirm non-administrators cannot edit the gateway; config exports and logs
   contain no custom IDs or PEM files. Delete a disposable gateway and verify
   both test and live private files are removed.

Automated runners are documented in the project Composer scripts and Makefile.
The Functional JavaScript gateway test supports `NOVAPAY_SCREENSHOT_DIR` for
capturing the conditional form. Record actual commands, screenshots, and any
external sandbox limitations in the TASK-028 handoff. Real sandbox checks need
authorized sandbox credentials and a public HTTPS callback. Follow the existing
sandbox refund limitations; successful production refunds are separate UAT.


# Capture identity regression

- Partial capture sends the initial operation ID, nested amount/recipient, and
  no root amount. A verified paid/hold_confirmed callback with a new transaction
  ID updates the existing `novapay_operation_id` atomically with capture state.
- Refund-status lookups use that new ID. After partial capture, only a full
  remaining-balance refund is available. Full refund and authorization void
  omit operations. Duplicate/late callbacks do not
  overwrite identity after capture completion. Missing, original-only or
  ambiguous partial-capture evidence preserves the authorization/pending marker
  and leaves the event unclaimed. Failed transitions roll back the ID too.
- A missing callback can be recovered through get-status while capture remains
  pending. No new database field, table, or migration is needed.
