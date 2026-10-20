# Taxmod eBay

eBay connection for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status: concept, nothing built.** The draft concept (German) lives with the modeller:
[`docs/pakete/ebay/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/konzept.md).

## Idea in short

- Connects to the owner's eBay account through the official eBay APIs (OAuth, one-time consent).
- Fetches sales (Sell Fulfillment API, fees from the Finances API) and, if the API allows it, purchases (Trading API `GetOrders`, buyer role).
- Turns each eBay order into a proposal in the same shape as [Taxmod Mail](https://github.com/sfambach/wp_tax_mail). The owner reviews it and picks the line items to import.
- Only the picked items are written, through the modeller's `taxmod/write-data` path. Buyer name and address are never stored, only the eBay user name.
- eBay orders come from the API, not from mails; duplicates are detected by platform and order number.
- Later: create listings and link records to running listings.
- Independent of the stock module; it only fires WordPress actions that other modules may listen to.

## License

Copyright (C) 2026 Stefan Fambach (SteFam), https://www.fambach.net — [PolyForm Noncommercial 1.0.0](LICENSE.md).
Commercial use needs the owner's prior consent.

## AI disclosure

This repository is created and maintained with the help of AI (Claude by Anthropic). All changes are reviewed by the owner.
