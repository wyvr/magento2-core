<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Logger\Logger;
use Wyvr\Core\Model\Block;
use Wyvr\Core\Model\Category;
use Wyvr\Core\Model\Page;
use Wyvr\Core\Model\Product;
use Wyvr\Core\Model\Cache;
use Wyvr\Core\Model\Settings;

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

    /** @var Page */
    protected $page;

    /** @var Block */
    protected $block;

    /** @var Cache */
    protected $cache;

    /** @var Settings */
    protected $settings;

    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface  $scopeConfig,
        Logger                $logger,
        Category              $category,
        Product               $product,
        Block                 $block,
        Page                  $page,
        Cache                 $cache,
        Settings              $settings,
    )
    {
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->category = $category;
        $this->product = $product;
        $this->page = $page;
        $this->block = $block;
        $this->cache = $cache;
        $this->settings = $settings;
    }

    public function rebuild(): void
    {
        $this->rebuild_settings();
        $this->rebuild_categories();
        $this->rebuild_products();
        $this->rebuild_pages();
        $this->rebuild_cache();
    }

    public function rebuild_categories(): void
    {
        $this->category->updateAll('cron categories');
    }

    public function rebuild_products(): void
    {
        $this->product->updateAll('cron products');
    }

    public function rebuild_pages(): void
    {
        $this->page->updateAll('cron pages');
        $this->block->updateAll('cron blocks');
    }

    public function rebuild_cache(): void
    {
        $this->cache->updateAll('cron cache');
    }

    public function rebuild_settings(): void
    {
        $this->settings->updateAll('cron config');
    }
}
