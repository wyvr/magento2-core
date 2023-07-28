<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Settings;
use Magento\Config\Controller\Adminhtml\System\Config\Save;

class ConfigSavePlugin
{
    public function __construct(
        protected Settings $settings
    )
    {
    }

    public function afterExecute(
        Save $subject,
             $result
    )
    {
        $this->settings->updateAll('config save');
        return $result;
    }
}
