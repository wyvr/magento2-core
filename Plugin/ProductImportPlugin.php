<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Product;

class ProductImportPlugin
{
    protected $product;

    public function __construct(
        Product $product
    ) {
        $this->product = $product;
    }

    public function afterImportSource(
        \Magento\ImportExport\Model\Import $subject,
        $result
    ) {
        if ($result && $subject->getEntity() == 'catalog_product') {
            $payload = ['product_import' => 1];
            
            // @TODO get ids of the products
        }

        return $result;
    }
}
