<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Category;

class CategoryDeletePlugin
{
    protected $category;

    public function __construct(
        Category $category
    ) {
        $this->category = $category;
    }

    public function afterExecute(
        \Magento\Catalog\Controller\Adminhtml\Category\Delete $subject,
        $result
    ) {
        $entityId = $subject->getRequest()->getParam('id');

        $this->category->delete($entityId);

        return $result;
    }
}
