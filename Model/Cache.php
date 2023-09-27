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

    public function __construct(
        protected ScopeConfigInterface      $scopeConfig,
        protected Logger                    $logger,
        protected CategoryCollectionFactory $categoryCollectionFactory,
        protected ProductCollectionFactory  $productCollectionFactory,
        protected StoreManagerInterface     $storeManager,
        protected ElasticClient             $elasticClient,
        protected WyvrProduct               $wyvrProduct,
        protected Status                    $productStatus,
        protected Visibility                $productVisibility,
    )
    {
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

                $products = $this->elasticClient->getIndexData('wyvr_product_' . $storeId);
                $products_map = [];

                foreach ($products as $product) {
                    $products_map[$product['_source']['id']] = $product['_source']['product'];
                }
                unset($products);

                foreach ($categories as $category) {
                    // avoid categories without url in cache
                    if (!$category->getUrlPath()) {
                        continue;
                    }
                    $category_products = $category->getProductCollection()
                        ->addAttributeToFilter('status', ['in' => $this->productStatus->getVisibleStatusIds()])
                        ->setVisibility($this->productVisibility->getVisibleInSiteIds())
                        ->getItems();
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
            }, self::INDEX, Constants::CACHE_STRUC, true);
        });
    }
}
