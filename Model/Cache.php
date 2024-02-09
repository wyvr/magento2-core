<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model;

use Elasticsearch\ClientBuilder;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Wyvr\Core\Logger\Logger;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Api\Constants;
use Wyvr\Core\Model\Product as WyvrProduct;
use Wyvr\Core\Service\ElasticClient;
use Wyvr\Core\Model\Product;


class Cache
{
    private const INDEX = 'cache';
    private const CATEGORY_INDEX = 'category_cache';

    public function __construct(
        protected ScopeConfigInterface      $scopeConfig,
        protected Logger                    $logger,
        protected CategoryCollectionFactory $categoryCollectionFactory,
        protected ProductCollectionFactory  $productCollectionFactory,
        protected StoreManagerInterface     $storeManager,
        protected ElasticClient             $elasticClient,
        protected Status                    $productStatus,
        protected Visibility                $productVisibility,
        protected Clear                     $clear
    )
    {
        $this->categoryIndexName = 'wyvr_' . self::CATEGORY_INDEX;
    }

    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('no trigger name specified', ['cache', 'update', 'all']);
            return;
        }

        $this->logger->measure($triggerName, ['cache', 'update', 'all'], function () {
            $this->elasticClient->iterateStores(function ($store, $indexName) {
                $storeId = $store->getId();
                $categories = $this->categoryCollectionFactory->create()
                    ->setStore($store)
                    ->addAttributeToSelect('*')
                    ->getItems();

                $products_map = $this->getProductsMap($storeId);

                foreach ($categories as $category) {
                    $this->updateCategory($indexName, $category, $products_map, $store);
                }
            }, self::INDEX, Constants::CACHE_STRUC, true);
        });
    }

    public function updatePartial(string $triggerName)
    {
        if (!$this->elasticClient->exists($this->categoryIndexName)) {
            return;
        }
        $data = $this->elasticClient->getIndexData($this->categoryIndexName);
        // clear the category cache index
        $this->elasticClient->destroy($this->categoryIndexName);

        $ids = array_column($data, '_id');

        if (count($ids) == 0) {
            return;
        }

        $this->logger->measure($triggerName, ['cache', 'update', 'partial'], function () use ($ids) {
            $this->elasticClient->iterateStores(function ($store, $indexName) use ($ids) {
                $storeId = $store->getId();
                $categories = $this->categoryCollectionFactory->create()
                    ->setStore($store)
                    ->addAttributeToSelect('*')
                    ->getItems();

                $products_map = $this->getProductsMap($storeId);

                foreach ($categories as $category) {
                    if (in_array($category->getId(), $ids)) {
                        $this->updateCategory($indexName, $category, $products_map, $store);
                        $this->clear->upsert('category', $category->getUrlPath() ?? '');
                    }
                }
            }, self::INDEX, Constants::CACHE_STRUC);
        });

        $this->logger->info(__('update %1 categories, %2', count($ids), join(',', $ids)), ['cache', 'update']);
    }

    /**
     * Add the given ids to the cache
     * @param array $ids
     * @return void
     */
    public function updateMany(array $ids)
    {
        $cleaned_ids = \array_unique($ids);
        $this->logger->info('cache update many ' . join(',', $cleaned_ids));

        try {
            $this->elasticClient->createIndex($this->categoryIndexName, Constants::CATEGORY_CACHE_STRUC);
            foreach ($ids as $id) {
                $this->elasticClient->update($this->categoryIndexName, ['id' => $id]);
            }
        } catch (\Exception $exception) {
            $this->logger->error(__('error category cache %1  %2 => %3', join(',', $cleaned_ids), $this->categoryIndexName, $exception->getMessage()));
            return;
        }
    }

    private function getProductsMap($storeId)
    {
        $products = $this->elasticClient->getIndexData('wyvr_product_' . $storeId);
        $products_map = [];

        foreach ($products as $product) {
            $products_map[$product['_source']['id']] = $product['_source']['product'];
        }
        unset($products);
        return $products_map;
    }

    public function updateCategory($indexName, $category, $products_map, $store)
    {
        // avoid categories without url in cache
        if (!$category->getUrlPath()) {
            return;
        }
        $collection = $this->productCollectionFactory->create()
            ->setStore($store)
            ->addAttributeToFilter('status', ['in' => $this->productStatus->getVisibleStatusIds()])
            ->setVisibility($this->productVisibility->getVisibleInCatalogIds())
            ->addCategoriesFilter(['in' => [$category->getId()]]);

        $category_products = $collection->getItems();

        $reduced_products = array_filter(array_map(function ($product) use ($products_map) {
            if (!array_key_exists($product->getId(), $products_map)) {
                return null;
            }
            $result = $products_map[$product->getId()];
            if (empty($result)) {
                return null;
            }
            unset($result['cross_sell_products']);
            unset($result['upsell_products']);
            unset($result['related_products']);
            return $result;
        }, $category_products), function ($p) {
            return !is_null($p);
        });
        $data = [
            'id' => $category->getId(),
            'products' => array_values($reduced_products)
        ];
        $this->elasticClient->update($indexName, $data);
    }
}
