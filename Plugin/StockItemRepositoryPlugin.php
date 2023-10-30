<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2023 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Product;

class StockItemRepositoryPlugin
{

    public function __construct(
        protected Product $product
    ) {
    }

    public function afterSave(
        \Magento\CatalogInventory\Model\Stock\StockItemRepository $subject,
        $result
    ) {
        $id = $result->getProductId();
        if($id) {
            $this->product->updateSingle($id);
        }
        return $result;
    }
}
