# Taxmod API eBay

eBay connection for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: connect, test, and purchases delivered to the modeller's Inbox (D-950, D-953); sales are read but not delivered yet.** Decided as D-943, D-950; proposals and review move into the modeller (D-952). Secrets are always stored encrypted (D-951). The draft concept (German) lives with the modeller:
[`docs/pakete/ebay/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/konzept.md).

## Idea in short

- Connects to the owner's eBay account through the official eBay APIs (OAuth, one-time consent).
- Fetches sales (Sell Fulfillment API, fees from the Finances API) and, if the API allows it, purchases (Trading API `GetOrders`, buyer role).
- Turns each eBay order into a proposal in the same shape as [Taxmod Mail](https://github.com/sfambach/wp_tax_mail). The owner reviews it and picks the line items to import.
- Needs the Taxonomy Modeller 0.1.35 or later. Registers as source «ebay» of the modeller's Inbox (`taxmod_import_sources`): its vault seals every secret (D-951) and its inbox owns proposals and review (D-952, D-953).
- Only the picked items are written, through the modeller's `taxmod/write-data` path. Buyer name and address are never stored, only the eBay user name.
- eBay orders come from the API, not from mails; duplicates are detected by platform and order number.
- Later: create listings and link records to running listings.
- Independent of the stock module; it only fires WordPress actions that other modules may listen to.

## Setup (step 1)

1. At [developer.ebay.com](https://developer.ebay.com) create an account and an application keyset (Production).
2. Under *User Tokens* create a RuName (eBay Redirect URL name). Enter the two URLs shown on the plugin page
   *Taxonomy Modeller → Configuration → eBay* (tab, D-945): «Auth accepted URL» and «Auth declined URL».
3. Enter the keys on the plugin page (the Cert ID is stored encrypted), or define them in `wp-config.php`, which
   takes precedence. Never post them in a chat:

   ```php
   define('TAXEBAY_CLIENT_ID', '…');      // App ID
   define('TAXEBAY_CLIENT_SECRET', '…');  // Cert ID
   define('TAXEBAY_RUNAME', '…');         // the RuName, not the URL
   // define('TAXEBAY_SANDBOX', true);    // only for the sandbox keyset
   ```

4. On the plugin page click «Connect to eBay», sign in and agree. Then «Test connection».
5. Purchases and sales (sales need modeller 0.1.47, D-979) arrive in *Taxonomy Modeller → Inbox*, automatically (default twice a day; 2–4 times a day recommended) or by its button.
6. Sales not shipped yet are listed on *Taxonomy Modeller → eBay shipping* (D-976): print the insert for the parcel,
   enter carrier and tracking number, «Mark shipped» reports it to eBay. The buyer's address is read live and never
   stored. From 0.0.15 the module asks for `sell.fulfillment` (write); an older consent needs «Connect again».

The refresh token is stored sealed (libsodium) with a key derived from the auth salt in `wp-config.php`; changing that
salt means connecting again. Production keysets also need the *Marketplace account deletion* notification settled in
the developer portal; this module stores only the eBay user name, so the exemption applies (D-943). The endpoint
accepts a notification only when eBay's `X-EBAY-SIGNATURE` verifies (ECDSA, public key from the Notification API);
anything else gets HTTP 412 and leaves no trace.

## License

Copyright (C) 2026 Stefan Fambach (SteFam), https://www.fambach.net — [PolyForm Noncommercial 1.0.0](LICENSE.md).
Commercial use needs the owner's prior consent.

## Third-party code

- `assets/pdfjs/` — [PDF.js](https://mozilla.github.io/pdf.js/) 3.11.174, © Mozilla Foundation, Apache License 2.0. Reads the eBay label PDF in the browser for the 62 mm print.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
