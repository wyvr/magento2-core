<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Page;
use Magento\Cms\Controller\Adminhtml\Page\Save;

class PageSavePlugin
{
    public function __construct(
        protected Page $page
    )
    {
    }

    public function afterExecute(
        Save $subject,
             $result
    )
    {
        $page_id = $subject->getRequest()->getParam('page_id');
        if ($page_id) {
            $this->page->updateSingle($page_id);
        }
        return $result;
    }
}
