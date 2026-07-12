<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class FeedGoogleShoppingFeedModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;

    public function initContent()
    {
        header('Content-Type: application/xml; charset=utf-8');

        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $currency = $this->context->currency;
        $link = $this->context->link;
        $shopName = Configuration::get('PS_SHOP_NAME');

        $products = $this->getActiveProductsWithCombinations($idLang, $idShop);

        echo $this->renderFeed($products, $shopName, $currency);
        exit;
    }

    /**
     * Fetches every active, combination-level "item" the feed needs to expose.
     * Google Shopping wants one entry per sellable variant (color, size, etc.),
     * not one per product, so we join to product_attribute when combinations exist.
     */
    private function getActiveProductsWithCombinations($idLang, $idShop)
    {
        $sql = '
            SELECT
                p.id_product,
                pa.id_product_attribute,
                pl.name,
                pl.description_short,
                pl.link_rewrite,
                p.reference AS product_reference,
                pa.reference AS combination_reference,
                p.price,
                p.id_tax_rules_group,
                cl.link_rewrite AS category_rewrite,
                cl.id_category,
                img.id_image
            FROM '._DB_PREFIX_.'product p
            INNER JOIN '._DB_PREFIX_.'product_lang pl
                ON pl.id_product = p.id_product AND pl.id_lang = '.(int) $idLang.' AND pl.id_shop = '.(int) $idShop.'
            INNER JOIN '._DB_PREFIX_.'product_shop ps
                ON ps.id_product = p.id_product AND ps.id_shop = '.(int) $idShop.'
            LEFT JOIN '._DB_PREFIX_.'product_attribute pa
                ON pa.id_product = p.id_product
            LEFT JOIN '._DB_PREFIX_.'category_lang cl
                ON cl.id_category = ps.id_category_default AND cl.id_lang = '.(int) $idLang.'
            LEFT JOIN '._DB_PREFIX_.'image img
                ON img.id_product = p.id_product AND img.cover = 1
            WHERE ps.active = 1
            GROUP BY p.id_product, pa.id_product_attribute
        ';

        return Db::getInstance()->executeS($sql);
    }

    private function renderFeed($products, $shopName, $currency)
    {
        $link = $this->context->link;
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $xml->startElement('channel');
        $xml->writeElement('title', $shopName.' Product Feed');
        $xml->writeElement('link', $link->getPageLink('index', true));
        $xml->writeElement('description', 'Google Shopping product feed for '.$shopName);

        foreach ($products as $row) {
            $idProduct = (int) $row['id_product'];
            $idProductAttribute = (int) $row['id_product_attribute'];

            $quantity = StockAvailable::getQuantityAvailableByProduct($idProduct, $idProductAttribute);
            $availability = $quantity > 0 ? 'in stock' : 'out of stock';

            $priceTaxIncl = Product::getPriceStatic(
                $idProduct,
                true,
                $idProductAttribute ?: null
            );

            $productUrl = $link->getProductLink($idProduct, $row['link_rewrite'], $row['category_rewrite']);
	    if ($idProductAttribute) {
		$productUrl .= (strpos($productUrl, '?') === false ? '?' : '&').'id_product_attribute='.$idProductAttribute;
	    }
            $imageUrl = $row['id_image']
                ? $link->getImageLink($row['link_rewrite'], $row['id_image'], 'large_default')
                : '';

            $itemId = $idProductAttribute ? $idProduct.'-'.$idProductAttribute : (string) $idProduct;
            $mpn = $row['combination_reference'] ?: $row['product_reference'];

            $xml->startElement('item');
            $xml->writeElement('g:id', $itemId);
            $xml->writeElement('g:title', $row['name']);
            $xml->writeElement('g:description', strip_tags($row['description_short']));
            $xml->writeElement('g:link', $productUrl);
            $xml->writeElement('g:image_link', $imageUrl);
            $xml->writeElement('g:availability', $availability);
            $xml->writeElement('g:price', number_format((float) $priceTaxIncl, 2, '.', '').' '.$currency->iso_code);
            $xml->writeElement('g:brand', $shopName);
            $xml->writeElement('g:condition', 'new');
            if ($mpn) {
                $xml->writeElement('g:mpn', $mpn);
            }
            $xml->endElement(); // item
        }

        $xml->endElement(); // channel
        $xml->endElement(); // rss
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
