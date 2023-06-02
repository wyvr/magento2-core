<?php

namespace Wyvr\Core\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Logger\Logger;

class Store
{
    protected static array $stores;
    private StoreManagerInterface $storeManager;
    private Logger $logger;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface  $scopeConfig,
        Logger                $logger,
    )
    {
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
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
                $this->logger->error('error in callback for store iterate ' . $store_id . ' ' . $exception->getMessage());
            }
        }
    }
}
