<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Wyvr\Core\Model\Block;
use Magento\Cms\Controller\Adminhtml\Block\Save;

class BlockSavePlugin
{
    public function __construct(
        protected Block                    $block,
        protected BlockRepositoryInterface $blockRepositoryInterface,
        protected SearchCriteriaBuilder    $searchCriteriaBuilder,
    )
    {
    }

    public function afterExecute(
        Save $subject,
             $result
    )
    {
        $id = $subject->getRequest()->getParam('block_id');
        if (!$id) {
            $blockIds = array_keys($this->blockRepositoryInterface->getList($this->searchCriteriaBuilder->create())->getItems());
            $id = $blockIds[count($blockIds) - 1];
        }
        if ($id) {
            $this->block->updateSingle($id);
        }
        return $result;
    }
}
