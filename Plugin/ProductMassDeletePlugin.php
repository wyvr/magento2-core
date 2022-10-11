<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Product;

class ProductMassDeletePlugin
{
    protected $product;

    public function __construct(
        Product $product
    ) {
        $this->product = $product;
    }

    public function afterExecute(
        \Magento\Catalog\Controller\Adminhtml\Product\MassDelete $subject,
        $result
    ) {
        $payload = ['massDelete' => 1];

        // delete products

        return $result;
    }
}
