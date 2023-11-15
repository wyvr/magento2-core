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
    public function __construct(
        protected Product                  $product,
        protected ProductCollectionFactory $productCollectionFactory,
    )
    {
    }

    public function afterExecute(
        Save $subject,
             $result
    )
    {
        $id = $subject->getRequest()->getParam('id');

        if (is_null($id)) {
            // new product
            // @TODO max item (set sort order)
            $newestProduct = $this->productCollectionFactory->create()->getLastItem();
            if ($newestProduct->hasData('entity_id')) {
                $id = $newestProduct->getEntityId();
            }
        }
        if ($id) {
            $this->product->updateSingle($id);
        }

        return $result;
    }
}
