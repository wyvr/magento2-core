<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Product;

class ProductSavePlugin
{
    protected $product;

    public function __construct(
        Product $product
    ) {
        $this->product = $product;
    }

    public function afterExecute(
        \Magento\Catalog\Controller\Adminhtml\Product\Save $subject,
        $result
    ) {
        $productId = $subject->getRequest()->getParam('id');
        $this->product->updateSingle($productId);
        return $result;
    }
}
