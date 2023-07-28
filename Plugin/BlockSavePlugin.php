<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Block;
use Magento\Cms\Controller\Adminhtml\Block\Save;

class BlockSavePlugin
{
    public function __construct(
        protected Block $block
    )
    {
    }

    public function afterExecute(
        Save $subject,
             $result
    )
    {
        $block_id = $subject->getRequest()->getParam('block_id');
        if ($block_id) {
            $this->block->updateSingle($block_id);
        }
        return $result;
    }
}
