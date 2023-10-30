<?php

namespace Wyvr\Core\Observer\Cobby;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Wyvr\Core\Logger\Logger;

class ProductImportAfter implements ObserverInterface
{
    public function __construct(protected Logger $logger)
    {
    }

    public function execute(Observer $observer)
    {
        $transportObject = $observer->getData('transport');
        $transportData = $transportObject->getData();
        if($transportData) {
            $this->logger->warning('cobby after import: ' . \json_encode($transportData));
        }
    }
}
