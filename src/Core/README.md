# src/Core

eBay environments, OAuth consent and token exchange, the identity call used by the connection test, reading sales and
purchases and mapping them to the modeller's `Taxmod\Core\Import\OrderProposal` (D-952). Sealing secrets is the
modeller's vault (D-951). Plain PHP 8.2, **no WordPress call** — HTTP goes through `Api\HttpTransport`, which the
boundary implements. The core test suite loads no WordPress. Concept:
[`docs/pakete/ebay/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/konzept.md).
