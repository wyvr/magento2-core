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
use Wyvr\Core\Logger\Logger;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Api\Constants;
use Wyvr\Core\Service\ElasticClient;

class Category
{
    private const INDEX = 'category';
    private string $indexName;

    public function __construct(
        protected ScopeConfigInterface      $scopeConfig,
        protected Logger                    $logger,
        protected CategoryFactory           $categoryFactory,
        protected CategoryCollectionFactory $categoryCollectionFactory,
        protected ProductCollectionFactory  $productCollectionFactory,
        protected StoreManagerInterface     $storeManager,
        protected ElasticClient             $elasticClient,
        protected Transform                 $transform,
        protected Clear                     $clear,
        protected Cache                     $cache
    )
    {
        $this->indexName = 'wyvr_' . self::INDEX;
    }

    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('no trigger name specified', ['category', 'update', 'all']);
            return;
        }

        $this->logger->measure($triggerName, ['category', 'update', 'all'], function () {
            $this->elasticClient->iterateStores(function ($store, $indexName) {
                $categories = $this->categoryCollectionFactory->create()
                    ->setStore($store)
                    ->addAttributeToSelect('*')
                    ->getItems();

                $this->logger->info(__('update %1 categories from store %2', count($categories), $store->getId()), ['category', 'update', 'all']);

                foreach ($categories as $category) {
                    $this->updateCategory($category, $store, $indexName, true);
                }
            }, self::INDEX, Constants::CATEGORY_STRUC, true);
        });
    }

    public function updateSingle($id)
    {
        if (empty($id)) {
            $this->logger->error('missing id', ['category', 'update']);
            return;
        }
        $this->logger->measure(__('category id "%1"', $id), ['category', 'update'], function () use ($id) {
            $this->elasticClient->iterateStores(function ($store, $indexName) use ($id) {
                $category = $this->categoryCollectionFactory->create()
                    ->setStore($store)
                    ->addAttributeToSelect('*')
                    ->addFieldToFilter('entity_id', $id)
                    ->getFirstItem();

                $this->updateCategory($category, $store, $indexName);
            }, self::INDEX, Constants::CATEGORY_STRUC);
        });
    }


    public function updateCategory($category, $store, $indexName, $avoid_clearing = false)
    {
        $id = $category->getEntityId();
        if (empty($id)) {
            $this->logger->error('can not update category because the id is not set', ['category', 'update']);
            return;
        }
        $data = $this->transform->convertBoolAttributes($category->getData(), Constants::CATEGORY_BOOL_ATTRIBUTES);

        // load products only for active categories
        if (array_key_exists('is_active', $data) && $data['is_active']) {
            $data['products'] = $this->getProductsOfCategory($id, $store);
        }
        $this->elasticClient->update($indexName, [
            'id' => $id,
            'url' => strtolower($category->getUrlPath() ?? ''),
            'name' => mb_strtolower($category->getName(), 'UTF-8'),
            'is_active' => $data['is_active'],
            'search' => $this->elasticClient->getSearchFromAttributes($this->scopeConfig->getValue(Constants::CATEGORY_INDEX_ATTRIBUTES), $data),
            'category' => $data
        ]);

        if (!$avoid_clearing) {
            $this->cache->updateMany([$id]);
        }
    }

    public function getProductsOfCategory($id, $store)
    {
        $products = $this->productCollectionFactory->create()
            ->setStore($store)
            ->addCategoriesFilter(['in' => [$id]])
            ->getItems();

        $enhanced_products = [];
        foreach ($products as $product) {
            $enhanced_products[] = [
                'id' => $product->getId(),
                'sku' => $product->getSku()
            ];
        }

        return $enhanced_products;
    }

    public function delete($id)
    {
        if (empty($id)) {
            $this->logger->error('can not delete category because the id is not set', ['category', 'delete']);
            return;
        }
        $this->elasticClient->iterateStores(function ($store, $indexName) use ($id) {
            $category = $this->categoryCollectionFactory->create()
                ->setStore($store)
                ->addAttributeToSelect('*')
                ->addFieldToFilter('entity_id', $id)
                ->getFirstItem();
            // delete from the category index
            $this->elasticClient->delete($indexName, $id);
            // delete from the cache index
            $this->elasticClient->delete('wyvr_cache_' . $store->getId(), $id);
            // mark for removing
            $this->clear->delete('category', $category->getUrlPath() ?? '');
        }, self::INDEX, Constants::CATEGORY_STRUC);
    }
}
