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

    protected ScopeConfigInterface $scopeConfig;
    protected Logger $logger;
    protected CategoryCollectionFactory $categoryCollectionFactory;
    protected ProductCollectionFactory $productCollectionFactory;
    protected StoreManagerInterface $storeManager;
    protected ElasticClient $elasticClient;
    protected WyvrProduct $wyvrProduct;
    protected Status $productStatus;
    protected Visibility $productVisibility;

    public function __construct(
        ScopeConfigInterface      $scopeConfig,
        Logger                    $logger,
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductCollectionFactory  $productCollectionFactory,
        StoreManagerInterface     $storeManager,
        ElasticClient             $elasticClient,
        WyvrProduct               $wyvrProduct,
        Status                    $productStatus,
        Visibility                $productVisibility,
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager = $storeManager;
        $this->elasticClient = $elasticClient;
        $this->wyvrProduct = $wyvrProduct;
        $this->productStatus = $productStatus;
        $this->productVisibility = $productVisibility;
    }

    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('cache updateAll No trigger name specified');
            return;
        }

        $this->logger->measure('cache updateAll "' . $triggerName . '"', function () {
            $this->elasticClient->iterateStores(function ($store) {
                $storeId = $store->getId();
                $categories = $this->categoryCollectionFactory->create()
                    ->setStore($store)
                    ->getItems();

                /*$products = $this->productCollectionFactory->create()
                    ->setStore($store)
                    ->addAttributeToFilter('status', ['in' => $this->productStatus->getVisibleStatusIds()])
                    ->setVisibility($this->productVisibility->getVisibleInSiteIds())
                    ->getItems();

                $products = array_map(function ($p) use ($store) {
                    return $this->product->getProductData($p, $store->getId());
                }, $products);*/
                $products = $this->elasticClient->getIndexData('wyvr_product_' . $storeId);
                $products_map = [];

                foreach ($products as $product) {
                    $products_map[$product['_source']['id']] = $product['_source']['product'];
                }
                unset($products);

                foreach ($categories as $category) {
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
                    $this->elasticClient->update($data);
                }
            }, self::INDEX, Constants::CACHE_STRUC, true);
        });
    }

    private function getProducts($store)
    {
    }

}
