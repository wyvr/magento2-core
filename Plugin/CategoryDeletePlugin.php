<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Category;

class CategoryDeletePlugin
{
    public function __construct(
        protected Category $category
    )
    {
    }

    public function afterExecute(
        \Magento\Catalog\Controller\Adminhtml\Category\Delete $subject,
                                                              $result
    )
    {
        $entityId = $subject->getRequest()->getParam('id');

        $this->category->delete($entityId);

        return $result;
    }
}
