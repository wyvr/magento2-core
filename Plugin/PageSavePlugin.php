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
    protected $page;

    public function __construct(
        Page $page
    )
    {
        $this->page = $page;
    }

    public function afterExecute(
        Save $subject,
             $result
    )
    {
        $page_id =$subject->getRequest()->getParam('page_id');
        if ($page_id) {
            $this->page->updateSingle($page_id);
        }
        return $result;
    }
}
