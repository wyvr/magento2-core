<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use Wyvr\Core\Model\Product;

class ProductMassAttributeSavePlugin
{
    private $ids;
    private $category_ids = [];

    public function __construct(
        protected Product           $product,
        protected Filter            $filter,
        protected CollectionFactory $collectionFactory
    )
    {
    }

    public function beforeExecute()
    {
        $this->ids = $this->filter->getCollection($this->collectionFactory->create())->getAllIds();
        return null;
    }

    public function afterExecute(
        $subject,
        $result
    )
    {
        $this->product->updateMany($this->ids);
        return $result;
    }
}
