<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Logger\Logger;
use Wyvr\Core\Model\Category;
use Wyvr\Core\Model\Product;

class CronManager
{
    /** @var Logger */
    protected $logger;

    /** @var StoreManagerInterface */
    protected $storeManager;

    /** @var ScopeConfigInterface */
    protected $scopeConfig;

    /** @var Category */
    protected $category;

    /** @var Product */
    protected $product;

    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface  $scopeConfig,
        Logger                $logger,
        Category              $category,
        Product               $product,
    ) {
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->category = $category;
        $this->product = $product;
    }

    public function rebuild_categories(): void
    {
        $this->category->updateAll('cron categories');
    }
    public function rebuild_products(): void
    {
        $this->product->updateAll('cron products');
    }
}
