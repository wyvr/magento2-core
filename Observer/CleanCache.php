<?php

namespace Wyvr\Core\Observer;

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

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        xdebug_break();
        $data = $observer->getEvent()->getObject();

        $this->logger->info(__('flush cache %1', json_encode($data)));
        $this->clear->upsert('*', '*');
    }
}
