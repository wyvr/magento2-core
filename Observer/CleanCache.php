<?php

namespace Wyvr\Core\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Wyvr\Core\Logger\Logger;
use Wyvr\Core\Model\Clear;

class CleanCache implements ObserverInterface
{
    public function __construct(
        protected Clear  $clear,
    )
    {
    }

    public function execute(Observer $observer)
    {
        $this->clear->all(__('flush cache'));
    }
}
