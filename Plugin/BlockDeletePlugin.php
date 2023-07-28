<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Block;
use Magento\Cms\Controller\Adminhtml\Block\Delete;

class BlockDeletePlugin
{
    public function __construct(
        protected Block $block
    )
    {
    }

    public function afterExecute(
        Delete $subject,
               $result
    )
    {
        $block_id = $subject->getRequest()->getParam('block_id');
        if ($block_id) {
            $this->block->delete($block_id);
        }
        return $result;
    }
}
