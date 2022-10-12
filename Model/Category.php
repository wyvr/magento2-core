<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model;

use Elasticsearch\ClientBuilder;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Wyvr\Core\Logger\Logger;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Service\ElasticClient;

class Category
{
    private const INDEX = 'category';

    /** @var ScopeConfigInterface */
    protected $scopeConfig;
    /** @var Logger */
    protected $logger;
    /** @var CategoryCollectionFactory */
    protected $categoryCollectionFactory;
    /** @var ProductCollectionFactory */
    protected $productCollectionFactory;
    /** @var StoreManagerInterface */
    protected $storeManager;

    /** @var ElasticClient */
    protected $elasticClient;

    private $cacheProductCollection = [];

    public function __construct(
        ScopeConfigInterface      $scopeConfig,
        Logger                    $logger,
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductCollectionFactory  $productCollectionFactory,
        StoreManagerInterface     $storeManager,
        ClientBuilder             $elasticSearchClient,
        ElasticClient             $elasticClient
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager = $storeManager;
        $this->elasticClient = $elasticClient;

    }

    public function updateSingle($id)
    {
        if (is_null($id)) {
            return;
        }
        $this->elasticClient->iterateStores(function ($store) use ($id) {
            $category = $this->categoryCollectionFactory->create()
                ->setStore($store)
                ->addAttributeToSelect('*')
                ->addFieldToFilter('entity_id', $id)
                ->getFirstItem();

            $this->updateCategory($category, $store);
        }, self::INDEX);
    }

    public function updateAll()
    {
        $this->elasticClient->iterateStores(function ($store, $indexName) {
            $categories = $this->categoryCollectionFactory->create()
                ->setStore($store)
                ->addAttributeToSelect('*')
                ->getItems();
            $this->logger->info("categories: " . count($categories) . " from store: " . $store->getId());
            foreach ($categories as $category) {
                $this->updateCategory($category, $store);
            }
        }, self::INDEX);
    }

    public function updateCategory($category, $store)
    {
        $id = $category->getEntityId();
        if (is_null($id)) {
            $this->logger->error('can not update category because the id is not set');
            return;
        }
        $data = $category->getData();
        // convert known attributes to bool
        foreach (['is_active', 'is_anchor', 'include_in_menu'] as $attr) {
            if (array_key_exists($attr, $data)) {
                $data[$attr] = $data[$attr] === '1';
            }
        }
        // load products only for active categories
        if (array_key_exists('is_active', $data) && $data['is_active']) {
            $data['products'] = $this->getProductsOfCategory($id, $store);
        }
        $this->elasticClient->update([
            'id' => $id,
            'url' => $category->getUrlKey(),
            'category' => json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR)
        ]);
    }

    public function getProductsOfCategory($id, $store)
    {
        $store_id = $store->getId();
        if (!array_key_exists($store_id, $this->cacheProductCollection)) {
            $this->cacheProductCollection[$store_id] = $this->productCollectionFactory->create()
                ->setStore($store)
                ->addAttributeToSelect('*');
        }
        $products = $this->cacheProductCollection[$store_id]
            ->addCategoriesFilter(['in' => [$id]])
            ->getItems();

        $enhanced_products = [];
        foreach ($products as $product) {
            $enhanced_products[] = $product->getData();
        }

        return $enhanced_products;
    }

    public function delete($id)
    {
        if (is_null($id)) {
            return;
        }
        $this->elasticClient->iterateStores(function () use ($id) {
            $this->elasticClient->delete($id);
        }, self::INDEX);
    }
}
