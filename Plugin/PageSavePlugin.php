<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Wyvr\Core\Model\Page;
use Magento\Cms\Controller\Adminhtml\Page\Save;

class PageSavePlugin
{
    public function __construct(
        protected Page                    $page,
        protected SearchCriteriaBuilder   $searchCriteriaBuilder,
        protected PageRepositoryInterface $pageRepositoryInterface,
    )
    {
    }

    public function afterExecute(
        Save $subject,
             $result
    )
    {
        $id = $subject->getRequest()->getParam('page_id');
        if (!$id) {
            $pageIds = array_keys($this->pageRepositoryInterface->getList($this->searchCriteriaBuilder->create())->getItems());
            $id = $pageIds[count($pageIds) - 1];
        }
        if ($id) {
            $this->page->updateSingle($id);
        }
        return $result;
    }
}
