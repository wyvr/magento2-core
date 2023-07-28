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
        protected CollectionFactory $collectionFactory,
        protected ProductRepository $productRepository
    )
    {
    }

    public function beforeExecute()
    {
        $this->ids = $this->filter->getCollection($this->collectionFactory->create())->getAllIds();
        foreach ($this->ids as $id) {
            $product = $this->productRepository->getById($id);
            $this->category_ids[$id] = $product->getCategoryIds();
        }
        return null;
    }

    public function afterExecute(
        $subject,
        $result
    )
    {
        foreach ($this->ids as $id) {
            $this->product->updateSingle($id, $this->category_ids[$id]);
        }
        return $result;
    }
}
