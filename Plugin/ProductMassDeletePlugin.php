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
    protected $product;
    protected $filter;
    protected $collectionFactory;

    public function __construct(
        Product           $product,
        Filter            $filter,
        CollectionFactory $collectionFactory

    ) {
        $this->product = $product;
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
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
