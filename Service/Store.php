<?php

namespace Wyvr\Core\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Logger\Logger;

class Store
{
    protected static array $stores;

    public function __construct(
        protected StoreManagerInterface $storeManager,
        protected ScopeConfigInterface  $scopeConfig,
        protected Logger                $logger,
    )
    {
        $this::$stores = $this->storeManager->getStores();
    }

    public function iterate(callable $callback): void
    {
        if (!is_callable($callback)) {
            $this->logger->error('missing/invalid callback in store iterate');
            return;
        }
        foreach ($this::$stores as $store) {
            $store_id = $store->getId();
            try {
                $callback($store);
            } catch (\Exception $exception) {
                $this->logger->error(__('error in callback for store iterate %1, %2', $store_id, $exception->getMessage()));
            }
        }
    }
}
