<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Product;

class ProductCopyPlugin
{
    protected $product;

    public function __construct(
        Product $product
    ) {
        $this->product = $product;
    }

    public function afterCopy(
        \Magento\Catalog\Model\Product\Copier $subject,
        $result,
        $product
    ) {
        $productId = $result->getEntityId();

        $this->product->updateSingle($productId);

        return $result;
    }
}
