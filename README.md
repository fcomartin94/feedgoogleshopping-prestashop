# Feed Export for Google Shopping — PrestaShop Module

A custom PrestaShop module that generates a public XML product feed compatible with
Google Merchant Center (Google Shopping), built from scratch — not a configuration
task, a real module with a front controller, database queries, and XML generation.

## Why this project

The other three projects in this portfolio (Mythic Books, Brew & Co., Volt & Circuit)
are about building and auditing stores — configuring platforms, fixing SEO issues,
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

## Progress Log

### Step 1 — Module design

Decided on the module's technical name (`feedgoogleshopping` — lowercase, no
separators, per PrestaShop convention), folder structure
(`feedgoogleshopping.php` + `controllers/front/feed.php` + `config.xml`), and the
field mapping from PrestaShop's data model to Google Merchant Center's required feed
fields (see table above). Chose to key feed items on `id_product` +
`id_product_attribute` (when combinations exist) rather than one row per product,
since Google Shopping expects one entry per sellable variant, not per product.

### Step 2 — `config.xml`

Module manifest: name, display name, version, author, tags, PrestaShop version
compliancy (`8`). This is the minimal descriptor PrestaShop reads to list the module
in the back office before it's even installed.

### Step 3 — `feedgoogleshopping.php`

Main module class extending `Module`. Minimal implementation: constructor sets
`name`, `tab` (`front_office_features`), `version`, `ps_versions_compliancy`, and
calls `parent::__construct()`. `install()`/`uninstall()` just delegate to the parent
— no hooks are registered, since the feed is served entirely through a front
controller rather than injected into any theme template.

### Step 4 — `controllers/front/feed.php`

The core of the module. `FeedGoogleShoppingFeedModuleFrontController extends
ModuleFrontController`, overriding `initContent()` to:

1. Query active products joined with `product_attribute` (combinations),
   `product_lang`, `category_lang`, and cover `image` — one row per sellable variant.
2. For each row, resolve real stock via `StockAvailable::getQuantityAvailableByProduct()`
   and real tax-included price via `Product::getPriceStatic()`, rather than reading
   raw `price` (tax-excluded, no discounts applied).
3. Build the product URL and image URL via `Context::getContext()->link` (PrestaShop's
   own URL-building helpers), so friendly URLs and multi-shop/multi-lang setups are
   respected automatically instead of hand-building link strings.
4. Write the RSS/`g:` XML with `XMLWriter`, which handles character escaping
   correctly (product names/descriptions can contain `&`, `<`, `>`, quotes) — safer
   than string concatenation.

### Step 5 — Installing and testing against Volt & Circuit

Copied the module into the running Docker container and installed it from the back
office (**Modules → Module Manager → Feed Export for Google Shopping → Install**):

```bash
docker cp feedgoogleshopping volt-circuit-prestashop:/var/www/html/modules/feedgoogleshopping
```

Loaded `http://localhost:8080/module/feedgoogleshopping/feed` directly. **Result: it
worked on the first try** — 26 `<item>` entries (matching the 26 combinations seeded
in Volt & Circuit), correct tax-included prices, correct stock-derived availability,
correct images, and correct MPNs per variant, including the 4-way Color × Model
combinations on Terra Leather Case (verified each of the 4 combinations produced a
distinct, correctly-labeled item).

**Bug found and fixed: variant URLs used a `#` fragment, unusable for Google.**
`Link::getProductLink()` with an `$idProductAttribute` argument produced URLs like
`.../1-aether-wireless-headphones.html#/color-black` — a URL fragment, which is never
sent to the server. This is the exact same PrestaShop 8.1 behavior already documented
as Finding 4 in the `volt-circuit-prestashop-seo` SEO audit project. For a feed meant
to be crawled by Google, this is a real defect: Google would always land on the
default variant regardless of which specific variant the feed item claims to
represent — a problem if variants differ in price, image, or availability.

**Fix**: build the base product URL without the attribute argument, then manually
append PrestaShop's real query-string parameter instead:

```php
$productUrl = $link->getProductLink($idProduct, $row['link_rewrite'], $row['category_rewrite']);
if ($idProductAttribute) {
    $productUrl .= (strpos($productUrl, '?') === false ? '?' : '&').'id_product_attribute='.$idProductAttribute;
}
```

`?id_product_attribute=X` is read server-side by PrestaShop and selects the correct
combination on first request — no JavaScript or fragment dependency. Re-copied the
updated controller and re-verified: URLs like
`.../1-aether-wireless-headphones.html?id_product_attribute=2` now appear correctly
in the feed, one distinct crawlable URL per variant.

**Takeaway**: this is a good example of a bug that showed up identically in two
different projects in this portfolio (the audit and this module) — recognizing it
from prior work made diagnosis immediate instead of starting from scratch.

## Screenshots

See `/screenshots`:
- `module_installed.png` — module listed and installed in the PrestaShop back office
- `feed_output.png` — the generated XML feed, rendered in the browser

<!-- Next: build the WooCommerce equivalent, then push both to GitHub and add to the portfolio. -->
