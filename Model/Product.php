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

class Product extends ElasticClient
{
    private const INDEX = 'product';

    /** @var ScopeConfigInterface */
    protected $scopeConfig;
    /** @var Logger */
    protected $logger;
    /** @var ProductCollectionFactory */
    protected $productCollectionFactory;
    /** @var StoreManagerInterface */
    protected $storeManager;

    /** @var ElasticClient */
    protected $elasticClient;

    public function __construct(
        ScopeConfigInterface      $scopeConfig,
        Logger                    $logger,
        ProductCollectionFactory  $productCollectionFactory,
        StoreManagerInterface     $storeManager,
        ClientBuilder             $elasticSearchClient,
        ElasticClient             $elasticClient
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager = $storeManager;
        $this->elasticClient = $elasticClient;

        parent::__construct(
            $storeManager,
            $scopeConfig,
            $elasticSearchClient,
            $logger
        );
    }

    public function updateSingle($id)
    {
        if(is_null($id)) {
            return;
        }

        $this->elasticClient->iterateStores(function ($store) use ($id) {
            $product = $this->productCollectionFactory->create()
                ->setStore($store)
                ->addAttributeToSelect('*')
                ->addFieldToFilter('entity_id', $id)
                ->getFirstItem();

            $this->updateProduct($product, $store);

        }, self::INDEX);
    }

    public function updateAll()
    {
//        $this->elasticClient->iterateStores(function ($store, $indexName) {
//            $categories = $this->categoryCollectionFactory->create()
//                ->setStore($store)
//                ->addAttributeToSelect('*')
//                ->getItems();
//            $this->logger->info("categories: " . count($categories) . " from store: " . $store->getId());
//            foreach ($categories as $category) {
//                $this->updateCategory($category, $store);
//            }
//        }, self::INDEX);
    }

    public function updateProduct($product, $store)
    {
        $id = $product->getEntityId();
        if(is_null($id)) {
            $this->logger->error('can not update category because the id is not set');
            return;
        }
        $data = $product->getData();
        // convert known attributes to bool
//        foreach (['is_active', 'is_anchor', 'include_in_menu'] as $attr) {
//            if (array_key_exists($attr, $data)) {
//                $data[$attr] = $data[$attr] === '1';
//            }
//        }
        $this->elasticClient->update([
            'id' => $id,
            'url' => $product->getUrlKey(),
            'product' => json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR)
        ]);
    }
}
