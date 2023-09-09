<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Wyvr\Core\Model\Product;
use Magento\Catalog\Controller\Adminhtml\Product\Save;

class ProductSavePlugin
{
    private $category_ids;
    private $id;

    public function __construct(
        protected Product                  $product,
        protected ProductRepository        $productRepository,
        protected ProductCollectionFactory $productCollectionFactory,
    )
    {
    }

    public function afterExecute(
        Save $subject,
             $result
    )
    {
        $product = null;
        $id = $subject->getRequest()->getParam('id');

        if (is_null($id)) {
            // new product
            $newestProduct = $this->productCollectionFactory->create()->getLastItem();
            if ($newestProduct->hasData('entity_id')) {
                $id = $newestProduct->getEntityId();
                $product = $newestProduct;
            }
        }
        // product was updated
        if ($id && !$product) {
            $product = $this->productRepository->getById($id);
        }

        if ($product) {
            $categoryIds = $product->getCategoryIds();
            $this->product->updateSingle($id, $categoryIds);
        }

        return $result;
    }
}
