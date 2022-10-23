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
    protected $product;
    protected $filter;
    protected $collectionFactory;
    protected $productRepository;
    private $ids;
    private $category_ids = [];

    public function __construct(
        Product           $product,
        Filter            $filter,
        CollectionFactory $collectionFactory,
        ProductRepository $productRepository
    ) {
        $this->product = $product;
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->productRepository = $productRepository;
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
    ) {
        foreach ($this->ids as $id) {
            $this->product->updateSingle($id, $this->category_ids[$id]);
        }
        return $result;
    }
}
