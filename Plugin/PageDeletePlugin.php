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
    protected $page;

    public function __construct(
        Page $page
    )
    {
        $this->page = $page;
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
