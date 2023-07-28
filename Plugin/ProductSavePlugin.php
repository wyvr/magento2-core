<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Magento\Catalog\Model\ProductRepository;
use Wyvr\Core\Model\Product;
use Magento\Catalog\Controller\Adminhtml\Product\Save;

class ProductSavePlugin
{
    private $category_ids;

    public function __construct(
        protected Product           $product,
        protected ProductRepository $productRepository
    )
    {
    }

    public function beforeExecute(
        Save $subject
    )
    {
        $id = $subject->getRequest()->getParam('id');
        $product = $this->productRepository->getById($id);
        $this->category_ids = $product->getCategoryIds();
        return null;
    }

    public function afterExecute(
        Save $subject,
             $result
    )
    {
        $productId = $subject->getRequest()->getParam('id');
        $this->product->updateSingle($productId, $this->category_ids);
        return $result;
    }
}
