<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use Wyvr\Core\Model\Product;

class ProductMassDeletePlugin
{
    public function __construct(
        protected Product           $product,
        protected Filter            $filter,
        protected CollectionFactory $collectionFactory

    )
    {
    }

    public function beforeExecute()
    {
        $ids = $this->filter->getCollection($this->collectionFactory->create())->getAllIds();
        foreach ($ids as $id) {
            $this->product->delete($id);
        }
        return null;
    }
}
