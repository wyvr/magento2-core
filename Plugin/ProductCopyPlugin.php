<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Product;

class ProductCopyPlugin
{
    public function __construct(
        protected Product $product
    )
    {
    }

    public function afterCopy(
        \Magento\Catalog\Model\Product\Copier $subject,
                                              $result
    )
    {
        $productId = $result->getEntityId();
        $this->product->updateSingle($productId);
        return $result;
    }
}
