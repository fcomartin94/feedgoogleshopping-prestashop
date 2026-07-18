# Feed Export for Google Shopping — PrestaShop Module

A custom PrestaShop module that generates a public XML product feed compatible with
Google Merchant Center (Google Shopping), built from scratch — not a configuration
task, a real module with a front controller, database queries, and XML generation.

## Why this project

The other projects in this portfolio (Mythic Books, Brew & Co., Volt & Circuit) are
about building and auditing stores — configuring platforms, fixing SEO issues,
managing catalogs. This project is different on purpose: it demonstrates writing
actual PrestaShop module code (hooks, controllers, the module lifecycle), which is
what separates "store operator" from "platform developer" in a client's eyes.

## What it does

Once installed, the module exposes a public endpoint
(`/module/feedgoogleshopping/feed`) that returns an RSS 2.0 XML feed with the
`g:` (Google) namespace, containing one `<item>` per sellable product variant —
matching the format Google Merchant Center expects for automatic feed ingestion.

Fields included per item: `g:id`, `g:title`, `g:description`, `g:link`,
`g:image_link`, `g:availability` (derived from real stock), `g:price` (tax included,
correct currency), `g:brand`, `g:condition`, `g:mpn`.

## Stack

- PrestaShop 8.1 module architecture (PHP, no external dependencies)
- Tested against the **Volt & Circuit** store (see the `volt-circuit-prestashop-seo`
  project in this portfolio), running on the same Docker Compose environment
- `XMLWriter` for safe, correctly-escaped XML generation (no manual string
  concatenation)

## Implementation

Module structure follows PrestaShop convention: `feedgoogleshopping.php` (main module
class extending `Module`), `controllers/front/feed.php` (the front controller serving
the feed), `config.xml` (manifest). No hooks are registered — the feed is served
entirely through the front controller rather than injected into any theme template.

Feed items are keyed on `id_product` + `id_product_attribute` (when combinations
exist) rather than one row per product, since Google Shopping expects one entry per
sellable variant, not per product.

`FeedGoogleShoppingFeedModuleFrontController` overrides `initContent()` to:

1. Query active products joined with `product_attribute` (combinations),
   `product_lang`, `category_lang`, and cover `image` — one row per sellable variant.
   This uses a direct `Db::getInstance()->executeS()` query instead of PrestaShop's
   `Product`/`Category` ORM classes: the ORM has no built-in way to fetch "one row
   per combination across all products" in a single call, so doing it through the
   ORM would mean looping over every `Product`, then every combination inside it,
   issuing separate queries each time. For a feed endpoint that has to run fast and
   stay lightweight, one SQL query with the necessary `JOIN`s is the right tool here
   — the ORM is used everywhere else in the controller (stock, pricing, URLs) where
   it doesn't come with that cost.
2. Resolves real stock via `StockAvailable::getQuantityAvailableByProduct()` and
   real tax-included price via `Product::getPriceStatic()`, rather than reading raw
   `price` (tax-excluded, no discounts applied).
3. Builds product and image URLs via `Context::getContext()->link` (PrestaShop's own
   URL-building helpers), so friendly URLs and multi-shop/multi-lang setups are
   respected automatically instead of hand-building link strings.
4. Writes the RSS/`g:` XML with `XMLWriter`, which handles character escaping
   correctly (product names/descriptions can contain `&`, `<`, `>`, quotes) — safer
   than string concatenation.

### Variant URLs and the `#` fragment issue

`Link::getProductLink()` with an `$idProductAttribute` argument produces URLs using a
`#` fragment for the variant (e.g. `.../headphones.html#/color-black`) — a fragment is
never sent to the server, so Google would always land on the default variant
regardless of which specific variant a given feed item claims to represent. This
matters when variants differ in price, image, or availability, and is the same
PrestaShop 8.1 behavior documented in the `volt-circuit-prestashop-seo` SEO audit.

Fixed by building the base product URL without the attribute argument, then appending
PrestaShop's real query-string parameter manually:

```php
$productUrl = $link->getProductLink($idProduct, $row['link_rewrite'], $row['category_rewrite']);
if ($idProductAttribute) {
    $productUrl .= (strpos($productUrl, '?') === false ? '?' : '&').'id_product_attribute='.$idProductAttribute;
}
```

`?id_product_attribute=X` is read server-side by PrestaShop and selects the correct
combination on the first request — no JavaScript or fragment dependency, and each
variant gets a distinct, crawlable URL.

## Testing

Installed into the Volt & Circuit store (`docker cp` into the running container,
installed from **Modules → Module Manager**). The feed at
`/module/feedgoogleshopping/feed` returns 26 `<item>` entries matching the 26 seeded
combinations, with correct tax-included prices, stock-derived availability, images,
and MPNs per variant — including the 4-way Color × Model combinations on one product,
each producing a distinct, correctly-labeled item.

## Screenshots

See `/screenshots`:
- `module_installed.png` — module listed and installed in the PrestaShop back office
- `feed_output.png` — the generated XML feed, rendered in the browser
