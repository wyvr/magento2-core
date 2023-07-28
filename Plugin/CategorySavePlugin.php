<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Category;

class CategorySavePlugin
{
    public function __construct(
        protected Category $category
    )
    {
    }

    public function afterExecute(
        \Magento\Catalog\Controller\Adminhtml\Category\Save $subject,
                                                            $result
    )
    {
        $entityId = null;
        $data = $subject->getRequest()->getPostValue();
        if ($data && isset($data['entity_id']) && $data['entity_id']) {
            $entityId = $data['entity_id'];
        }

        $this->category->updateSingle($entityId);

        return $result;
    }
}
