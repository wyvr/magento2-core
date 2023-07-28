<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Wyvr\Core\Model\Category;

class CategorySavePlugin
{
    public function __construct(
        protected Category                  $category,
        protected CategoryCollectionFactory $categoryCollectionFactory,

    )
    {
    }

    public function afterExecute(
        \Magento\Catalog\Controller\Adminhtml\Category\Save $subject,
                                                            $result
    )
    {
        $id = null;
        $data = $subject->getRequest()->getPostValue();
        if ($data && array_key_exists('entity_id', $data) && $data['entity_id']) {
            $id = $data['entity_id'];
        }
        if (!$id) {
            // new category
            $newestCategory = $this->categoryCollectionFactory->create()->getLastItem();
            if ($newestCategory->hasData('entity_id')) {
                $id = $newestCategory->getEntityId();
            }
        }
        if ($id) {
            $this->category->updateSingle($id);
        }

        return $result;
    }
}
