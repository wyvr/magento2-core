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
        protected Logger $logger
    )
    {
    }

    public function execute(Observer $observer)
    {
        $this->logger->info(__('flush cache'));
        $this->clear->set('*', '*', 'clear');
    }
}
