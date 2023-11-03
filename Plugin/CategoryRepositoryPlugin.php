<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2023 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Category;

class CategoryRepositoryPlugin
{
    public function __construct(
        protected Category $category
    )
    {
    }

    public function afterSave(
        \Magento\Catalog\Model\CategoryRepository $subject,
                                                  $result
    )
    {
        $id = $result->getId();
        if ($id) {
            $this->category->updateSingle($id);
        }

        return $result;
    }
}
