<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Page;
use Magento\Cms\Controller\Adminhtml\Page\Delete;

class PageDeletePlugin
{
    public function __construct(
        protected Page $page
    )
    {
    }

    public function afterExecute(
        Delete $subject,
               $result
    )
    {
        $page_id = $subject->getRequest()->getParam('page_id');
        if ($page_id) {
            $this->page->delete($page_id);
        }
        return $result;
    }
}
