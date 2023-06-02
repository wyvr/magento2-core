<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model;

use Elasticsearch\ClientBuilder;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Wyvr\Core\Logger\Logger;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Api\Constants;
use Wyvr\Core\Service\ElasticClient;
use Wyvr\Core\Service\Store;

class Settings
{
    private const INDEX = 'settings';

    protected ScopeConfigInterface $scopeConfig;
    protected Logger $logger;
    protected CategoryFactory $categoryFactory;
    protected CategoryCollectionFactory $categoryCollectionFactory;
    protected ProductCollectionFactory $productCollectionFactory;
    protected StoreManagerInterface $storeManager;
    protected ElasticClient $elasticClient;
    protected Store $store;

    public function __construct(
        ScopeConfigInterface      $scopeConfig,
        Logger                    $logger,
        CategoryFactory           $categoryFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductCollectionFactory  $productCollectionFactory,
        StoreManagerInterface     $storeManager,
        ElasticClient             $elasticClient,
        Store                     $store
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->categoryFactory = $categoryFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager = $storeManager;
        $this->elasticClient = $elasticClient;
        $this->store = $store;
    }

    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('settings updateAll No trigger name specified');
            return;
        }

        $this->logger->measure('settings updateAll "' . $triggerName . '"', function () {
            $alias = 'wyvr_' . self::INDEX;
            $versions = $this->elasticClient->getVersions($alias, true);
            $index_name = $alias . '_v' . $versions['version'];
            $this->store->iterate(function (StoreInterface $store) use ($index_name) {
                $store_id = $store->getId();

                $store_result = $this->getSettings($store_id);

                $this->elasticClient->setIndexName($index_name);
                $this->elasticClient->createIndex($index_name, Constants::SETTINGS_STRUC);
                $this->elasticClient->update([
                    'id' => $store_id,
                    'value' => $store_result
                ]);

            });
            $this->elasticClient->updateAlias($alias, $index_name, $versions['prev_aliases'], $versions['all']);
        });
    }

    public function getSettings($store_id)
    {
        $included_paths = $this->scopeConfig->getValue('wyvr/settings/included_paths', 'store', $store_id);
        if (!$included_paths) {
            return;
        }
        $paths = preg_split("/\r\n|\n|\r/", $included_paths);
        if (!is_array($paths) || count($paths) == 0) {
            $this->logger->warning('No settings paths configured for store ' . $store_id);
            return;
        }

        $store_result = [];
        foreach ($paths as $path) {
            if (!$path) {
                continue;
            }
            $split_path = explode('/', $path);
            if ($split_path === false) {
                continue;
            }

            $main = array_shift($split_path);
            if (empty($main)) {
                continue;
            }

            $value = $this->scopeConfig->getValue($path, 'store', $store_id);
            if (!array_key_exists($main, $store_result)) {
                $store_result[$main] = [];
            }
            if (count($split_path) == 0) {
                $store_result[$main] = $value;
                continue;
            }

            $pointer = &$store_result[$main];
            for ($i = 0; $i < sizeof($split_path); $i++) {
                if (!isset($pointer[$split_path[$i]])) {
                    $pointer[$split_path[$i]] = [];
                }
                $pointer =& $pointer[$split_path[$i]];
            }

            $pointer = $value;
        }
        return $store_result;
    }

}
