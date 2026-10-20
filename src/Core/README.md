# src/Core

eBay environments, OAuth consent and token exchange, the identity call used by the connection test, and the sealed
storage of the refresh token. Plain PHP 8.2, **no WordPress call** — HTTP goes through `Api\HttpTransport`, which the
boundary implements. The core test suite loads no WordPress. Concept:
[`docs/pakete/ebay/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/konzept.md).
