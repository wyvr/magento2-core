<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\ConfigurableProduct\Api\LinkManagementInterface;
use Magento\CatalogInventory\Model\Stock\StockItemRepository;
use Wyvr\Core\Api\Constants;
use Wyvr\Core\Logger\Logger;
use Wyvr\Core\Service\ElasticClient;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableProduct;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Wyvr\Core\Service\Store;

class Product
{
    private const INDEX = 'product';

    public function __construct(
        protected ScopeConfigInterface                $scopeConfig,
        protected Logger                              $logger,
        protected ProductCollectionFactory            $productCollectionFactory,
        protected ProductAttributeManagementInterface $productAttributeManagement,
        protected RuleFactory                         $catalogRuleFactory,
        protected StoreManagerInterface               $storeManager,
        protected ElasticClient                       $elasticClient,
        protected LinkManagementInterface             $linkManagement,
        protected StockItemRepository                 $stockItemRepository,
        protected ProductRepository                   $productRepository,
        protected Category                            $category,
        protected Transform                           $transform,
        protected ConfigurableProduct                 $configurableProduct,
        protected Clear                               $clear,
        protected Cache                               $cache,
        protected ManagerInterface                    $eventManager,
        protected ResourceConnection                  $resource,
        protected Store                               $store
    ) {
    }

    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('no trigger name specified', ['product', 'update', 'all']);
            return;
        }

        $this->logger->measure($triggerName, ['product', 'update', 'all'], function () {
            $this->elasticClient->iterateStores(function ($store, $indexName) {
                $products = $this->productCollectionFactory->create()
                    ->setStore($store)
                    ->getItems();

                $current = 1;
                $total = \count($products);
                $this->logger->info(__('update %1 products from store %2', $total, $store->getId()), ['product', 'update', 'all']);

                foreach ($products as $p) {
                    if ($current % 100 == 0) {
                        $this->logger->info(__('%2%, %1 products processed for store %3', $current, \round(100 / $total * $current), $store->getId()), ['product', 'update', 'all', 'process']);
                    }
                    $product = $this->productRepository->getById($p->getId(), false, $store->getId());
                    $this->updateProduct($product, $store, $indexName, false, false);
                    $current++;
                }
            }, self::INDEX, Constants::PRODUCT_STRUC, true);
        });
    }

    public function updateCache($triggerName): void
    {
        $category_ids = [];
        $context = ['product', 'cache', 'update'];

        $this->logger->measure($triggerName, $context, function () use (&$category_ids, &$context) {
            // load all product skus in the shop
            $selectProducts = "select entity_id, updated_at, type_id from catalog_product_entity;";
            $product_entities = $this->resource->getConnection()->query($selectProducts)->fetchAll();

            $result = [];

            $this->elasticClient->iterateStores(function ($store, $indexName) use (&$category_ids, &$product_entities, &$result, &$context) {
                $storeId = $store->getId();
                $totalInShop = \count($product_entities);
                $inserted = 0;
                $updated = 0;
                $cleaned = 0;
                $deleted = 0;

                // load all products from the elastic cache
                $cache = $this->elasticClient->getIndexData($indexName);
                $products_map = [];

                foreach ($cache as $product) {
                    $products_map[$product['_source']['id']] = $product['_source'];
                }
                unset($cache);

                // process the products
                $count = 0;
                foreach ($product_entities as $productEntity) {
                    $count++;
                    if ($count % 1000 == 0) {
                        $this->logger->info(__('processed %1/%2 %3%', $count, $totalInShop, round($count / $totalInShop * 100)), $context);
                    }
                    $id = $productEntity['entity_id'];
                    $product = $this->productRepository->getById($id, false, $storeId);

                    if (!\array_key_exists($id, $products_map)) {
                        $inserted++;
                        $category_ids = \array_merge($category_ids, $this->updateProduct($product, $store, $indexName, false, false));
                        continue;
                    }
                    $data = $products_map[$id];
                    // remove from the cache to check which products are in the cache that are no longer available
                    unset($products_map[$id]);

                    if ($data['product']['updated_at'] == $productEntity['updated_at'] && $productEntity['type_id'] == 'simple') {

                        $changed = $this->hasChanged($data['product'], $product);
                        // get the newest price
                        $this->appendPrice($data['product'], $product);
                        $this->appendStock($data['product'], $product);
                        // update the entry in the elastic
                        $this->elasticClient->update($indexName, $data);
                        if ($changed) {
                            $this->clear->upsert('product', $product->getUrlKey());
                            $category_ids = \array_merge($category_ids, $product->getCategoryIds());
                        }
                        $cleaned++;
                        continue;
                    }
                    $category_ids = \array_merge($category_ids, $this->updateProduct($product, $store, $indexName, false, false));
                    $this->clear->upsert('product', $product->getUrlKey());
                    $updated++;
                }
                $this->logger->info(__('processed %1/%2 %3%%', $count, $totalInShop, round($count / $totalInShop * 100)), $context);

                $this->logger->info(__('delete %1 products', \count($products_map)), $context);
                foreach ($products_map as $entry) {
                    $this->elasticClient->delete($indexName, $entry['id']);
                    $this->clear->delete('product', $entry['url']);
                    $deleted++;
                }
                unset($products_map);

                $this->logger->info(__('store %1: total %2, inserted %3, updated %4, cleaned %5, deleted %6', $storeId, $totalInShop, $inserted, $updated, $cleaned, $deleted), $context);
                $result[$storeId] = [
                    'totalInShop' => $totalInShop,
                    'inserted' => $inserted,
                    'updated' => $updated,
                    'cleaned' => $cleaned,
                    'deleted' => $deleted,
                ];
            }, self::INDEX, Constants::PRODUCT_STRUC);

            $clearCaches = \count($category_ids) > 0;
            if ($clearCaches) {
                $this->cache->updateMany($category_ids);
            }

            $this->eventManager->dispatch(Constants::EVENT_PRODUCT_CACHE_UPDATE_AFTER, ['result' => $result, 'clearCaches' => $clearCaches]);
        });
    }

    public function updateParentProducts($triggerName): void
    {
        if (!$this->elasticClient->exists(Constants::PARENT_PRODUCTS_NAME)) {
            return;
        }
        $data = $this->elasticClient->getIndexData(Constants::PARENT_PRODUCTS_NAME);
        // remove only the entries that are processed in this batch
        foreach ($data as $entry) {
            $this->elasticClient->delete(Constants::PARENT_PRODUCTS_NAME, $entry['_id']);
        }

        $context = ['product', 'parent', 'update'];
        $this->logger->measure($triggerName, $context, function () use (&$data, &$context) {
            $ignoredStores = $this->elasticClient->getIgnoredStores();
            $this->store->iterate(function ($store) use (&$data, &$ignoredStores, &$context) {
                $storeId = $store->getId();
                if (in_array($storeId, $ignoredStores)) {
                    return;
                }
                $this->logger->info(__('update %1 parent products', \count($data)), $context);
                foreach ($data as $entry) {
                    if (\array_key_exists('_source', $entry) && \array_key_exists('type_id', $entry['_source']) && $entry['_source']['type_id'] == 'configurable') {
                        $product = $this->productRepository->getById($entry['_id'], false, $storeId);
                        // force update the configurable product
                        $this->updateProduct($product, $store, 'wyvr_product_' . $storeId, false);
                    }
                }
            });
        });
    }

    public function updateSingle($id)
    {
        if (empty($id)) {
            $this->logger->error('can not update product because the id is not set', ['product', 'update']);
            return;
        }
        $this->logger->measure(__('product id "%1"', $id), ['product', 'update'], function () use ($id, &$category_ids) {
            $category_ids = [];

            $this->elasticClient->iterateStores(function ($store, $indexName) use ($id, &$category_ids) {
                $product = $this->productRepository->getById($id, false, $store->getId());
                $category_ids = \array_merge($category_ids, $this->updateProduct($product, $store, $indexName));
            }, self::INDEX, Constants::PRODUCT_STRUC);

            // update all affected categories
            if (\count($category_ids) > 0) {
                $this->cache->updateMany($category_ids);
            }
        });
    }

    public function updateSingleBySku($sku)
    {
        if (\count($sku) == 0) {
            $this->logger->error('can not update product because the sku is not set', ['product', 'update']);
            return;
        }
        $this->logger->measure(__('product sku "%1"', $sku), ['product', 'update'], function () use ($sku) {
            $category_ids = [];

            $this->elasticClient->iterateStores(function ($store, $indexName) use ($sku, &$category_ids) {
                $product = $this->productRepository->get($sku, false, $store->getId());
                $category_ids = \array_merge($category_ids, $this->updateProduct($product, $store, $indexName));
            }, self::INDEX, Constants::PRODUCT_STRUC);

            // update all affected categories
            if (\count($category_ids) > 0) {
                $this->cache->updateMany($category_ids);
            }
        });
    }

    public function updateMany(array $ids)
    {
        if (!is_array($ids) || \count($ids) == 0) {
            $this->logger->error('can not update product because the ids are not set', ['product', 'update']);
            return;
        }
        $this->logger->measure(__('product ids "%1"', join('","', $ids)), ['product', 'update'], function () use ($ids) {
            $category_ids = [];

            $this->elasticClient->iterateStores(function ($store, $indexName) use ($ids, &$category_ids) {
                foreach ($ids as $id) {
                    if (!$id) {
                        continue;
                    }
                    $product = $this->productRepository->getById($id, false, $store->getId());
                    $category_ids = \array_merge($category_ids, $this->updateProduct($product, $store, $indexName));
                }
            }, self::INDEX, Constants::PRODUCT_STRUC);

            // update all affected categories
            if (\count($category_ids) > 0) {
                $this->cache->updateMany($category_ids);
            }
        });
    }

    public function updateManyBySku(array $skus)
    {
        if (!is_array($skus) || \count($skus) == 0) {
            $this->logger->error('can not update product because the skus are not set', ['product', 'update']);
            return;
        }
        $this->logger->measure(__('products by sku %1', \count($skus)), ['product', 'update'], function () use ($skus) {
            $category_ids = [];

            $this->elasticClient->iterateStores(function ($store, $indexName) use ($skus, &$category_ids) {
                foreach ($skus as $sku) {
                    if (empty($sku)) {
                        continue;
                    }
                    $product = $this->productRepository->get($sku, false, $store->getId());
                    $category_ids = \array_merge($category_ids, $this->updateProduct($product, $store, $indexName));
                }
            }, self::INDEX, Constants::PRODUCT_STRUC);

            // update all affected categories
            if (\count($category_ids) > 0) {
                $this->cache->updateMany($category_ids);
            }
        });
    }

    public function delete(int|string $id)
    {
        if (empty($id)) {
            $this->logger->error('can not delete product because the id is not set', ['product', 'delete']);
            return;
        }
        $this->elasticClient->iterateStores(function ($store, $indexName) use ($id) {
            $product = $this->productRepository->getById($id, false, $store->getId());
            $this->elasticClient->delete($indexName, $id);
            $this->clear->delete('product', $product->getUrlKey());
        }, self::INDEX, Constants::PRODUCT_STRUC);
    }

    /**
     * Update the stock of a product without triggering full reindex of the product
     */
    public function updateStock($id): void
    {
        $this->logger->measure(__('stock of id "%1"', $id), ['stock', 'update'], function () use ($id, &$category_ids) {
            $category_ids = [];

            $this->elasticClient->iterateStores(function ($store, $indexName) use ($id, &$category_ids) {
                $product = $this->productRepository->getById($id, false, $store->getId());
                $data = $this->elasticClient->getById($indexName, $id);
                if (!$data || !$product) {
                    return;
                }
                // update quantity and stock status
                $this->appendStock($data['product'], $product);

                $this->elasticClient->update($indexName, $data);

                $this->clear->upsert('product', $data['url']);

                // trigger update of the parent products
                $this->updateParentProductsOfSimple($id, $product->getTypeId());

                $this->logger->info(__('update stock of id "%1"', $id), ['stock', 'update']);
            }, self::INDEX, Constants::PRODUCT_STRUC);
        });
    }

    /**
     * Update the price of a product without triggering full reindex of the product
     */
    public function updatePriceBySku($sku): void
    {
        $this->logger->measure(__('price of sku "%1"', $sku), ['price', 'update'], function () use ($sku, &$category_ids) {
            $category_ids = [];

            $this->elasticClient->iterateStores(function ($store, $indexName) use ($sku, &$category_ids) {
                $store_id = $store->getId();
                $product = $this->productRepository->get($sku, false, $store_id);
                if (!$product) {
                    return;
                }
                $id = $product->getId();
                $data = $this->elasticClient->getById($indexName, $id);
                if (!$data) {
                    return;
                }
                // get the newest price
                $this->appendPrice($data['product'], $product);
                // update the entry in the elastic
                $this->elasticClient->update($indexName, $data);
                // clear the product
                $this->clear->upsert('product', $data['url']);

                $category_ids = \array_merge($category_ids, $product->getCategoryIds());

                // dispatch event to allow easy react to updates
                $this->eventManager->dispatch(Constants::EVENT_PRODUCT_UPDATE_AFTER, ['product' => $product, 'category_ids' => $category_ids, 'store_id' => $store_id]);

                // trigger update of the parent products
                $this->updateParentProductsOfSimple($id, $product->getTypeId());

                $this->logger->info(__('update price of id "%1"', $id), ['price', 'update']);
            }, self::INDEX, Constants::PRODUCT_STRUC);
        });
    }

    public function updateProduct($product, $store, string $indexName, ?bool $partial_import = true, ?bool $fire_event = true): array
    {
        $id = $product->getEntityId();
        $storeId = $store->getId();
        if (empty($id)) {
            $this->logger->error('can not update product because the id is not set', ['product', 'update']);
            return [];
        }
        $category_ids = $product->getCategoryIds();
        // check if the product has to be updated, to avoid multiple updates in series
        // @WARN configurables should not be updated with partial_import because it gets triggered by the simple product and it will not be updated directly
        if ($partial_import) {
            $data = $this->elasticClient->getById($indexName, $id);
            if ($data && $data['updated_at'] === $product->getUpdatedAt()) {
                // product has not been changed, ignore
                if ($product->getTypeId() === 'configurable') {
                    $this->logger->warning(__('configurable %1 was ignored because updated_at has not been changed', $id), ['product', 'update']);
                }
                return [];
            }
            $prev_category_ids = $data['product']['category_ids']['value'] ?? [];
            if (\count($prev_category_ids) > 0) {
                $category_ids = \array_merge($category_ids, $prev_category_ids);
            }
        }
        $this->logger->debug(__('update product %1', $id), ['product', 'update']);

        $data = $this->getProductData($product, $storeId);
        $data['cross_sell_products'] = array_map(function ($p) use ($storeId) {
            return $this->getProductData($p, $storeId);
        }, $product->getCrossSellProducts());
        $data['upsell_products'] = array_map(function ($p) use ($storeId) {
            return $this->getProductData($p, $storeId);
        }, $product->getUpSellProducts());
        $data['related_products'] = array_map(function ($p) use ($storeId) {
            return $this->getProductData($p, $storeId);
        }, $product->getRelatedProducts());

        $search = $this->elasticClient->getSearchFromAttributes($this->scopeConfig->getValue(Constants::PRODUCT_INDEX_ATTRIBUTES), $data);

        $url = $product->getUrlKey();


        // allways update the parent configurable of the simple product
        $parentProducts = $this->updateParentProductsOfSimple($id, $product->getTypeId(), $partial_import);
        if (\count($parentProducts) > 0) {
            $data['parent_products'] = $parentProducts;
        }

        $this->elasticClient->update($indexName, [
            'id' => $id,
            'url' => strtolower($url),
            'sku' => strtolower($product->getSku()),
            'name' => strtolower($product->getName()),
            'visibility' => intval($product->getVisibility()),
            'search' => $search,
            'product' => $data,
            'created_at' => $data['created_at'],
            'updated_at' => $data['updated_at']
        ]);

        // mark the product to be re-executed
        if ($partial_import) {
            $this->clear->upsert('product', $url);
        }

        // dispatch event to allow easy react to updates
        if ($fire_event) {
            $this->eventManager->dispatch(Constants::EVENT_PRODUCT_UPDATE_AFTER, ['product' => $product, 'category_ids' => $category_ids, 'store_id' => $storeId]);
        }
        return $category_ids;
    }

    /**
     * Update the parent products of a simple product
     * @param int $id
     * @param string $type
     * @param bool|null $partial_import
     * @return array
     */
    public function updateParentProductsOfSimple(int $id, string $type, ?bool $partial_import = true): array
    {
        $parentProducts = [];
        // not required in a full reindex, because the configurable also gets processed
        if ($type != 'simple') {
            return $parentProducts;
        }
        // Get all parent ids of this product
        $parentIds = $this->configurableProduct->getParentIdsByChild($id);
        if (\count($parentIds) > 0) {
            // This means that the simple product is associated with a configurable product, load it
            foreach ($parentIds as $parentId) {
                try {
                    $configurableProduct = $this->productRepository->getById($parentId);
                } catch (\Exception $e) {
                    continue;
                }
                // ignore disabled parents
                if ($configurableProduct->getStatus() == Status::STATUS_DISABLED) {
                    continue;
                }
                $parent = [
                    'id' => $parentId,
                    'sku' => $configurableProduct->getSku(),
                    'url_key' => $configurableProduct->getUrlKey(),
                ];

                // not required in a full reindex, because the configurable also gets processed
                if ($partial_import) {
                    // mark product for later processing
                    $this->elasticClient->createIndex(Constants::PARENT_PRODUCTS_NAME, Constants::PARENT_PRODUCTS_STRUC);
                    $this->elasticClient->update(Constants::PARENT_PRODUCTS_NAME, ['id' => $parentId, 'type_id' => 'configurable']);
                }

                $parentProducts[] = $parent;
            }
        }

        return $parentProducts;
    }

    /**
     * Check if the product has changed against the data in the product index
     * @param array|null $data_product
     * @param \Magento\Catalog\Model\Product $product
     * @return bool
     */
    public function hasChanged(?array $data_product, $product): bool
    {
        if (!$data_product) {
            return true;
        }
        if ($data_product['final_price'] != $product->getFinalPrice()) {
            return true;
        }
        if (array_key_exists('quantity_and_stock_status', $data_product) && array_key_exists('value', $data_product['quantity_and_stock_status']) && $data_product['quantity_and_stock_status']['value'] != $product->getQuantityAndStockStatus()) {
            return true;
        }
        if ($data_product['stock'] != $this->getProductStock($product)) {
            return true;
        }
        return false;
    }

    public function getProductData($product, $storeId)
    {
        // get base data
        $data = $product->getData();
        // add the categories
        $data['category_ids'] = $product->getCategoryIds();
        // extend the attributes
        $this->appendAttributes($data, $product, $storeId);
        $this->appendStock($data, $product);
        $this->appendPrice($data, $product);
        $this->appendConfigurables($data, $product, $storeId);
        return $data;
    }

    public function appendConfigurables(&$data, $product): void
    {
        if ($product->getTypeId() !== 'configurable') {
            return;
        }
        $instance = $product->getTypeInstance();
        $data['configurable_products'] = array_map(function ($p) {
            // @NOTE getUsedProducts returns only a subset of all available product attributes, avoid duplicating data, the simples to the cionfigurables has to be loaded on the client
            return $p->getId();
        }, $instance->getUsedProducts($product));
        $data['configurable_options'] = $instance->getConfigurableOptions($product);
    }

    public function appendPrice(&$data, $product): void
    {
        $data['final_price'] = $product->getFinalPrice();

        $now = new \Datetime();
        $specialPriceFrom = $product->getSpecialFromDate();
        $specialPriceTo = $product->getSpecialToDate();
        if (($specialPriceFrom && $specialPriceFrom <= $now) && ($specialPriceTo && $specialPriceTo >= $now)) {
            $specialPrice = $product->getSpecialPrice();
        } else {
            $specialPrice = null;
        }
        $data['special_price'] = $specialPrice;

        $data['rule_price'] = $this->catalogRuleFactory->create()->calcProductPriceRule(
            $product,
            $product->getPrice()
        );
    }

    public function appendStock(&$data, $product): void
    {
        // update quantity and stock status
        if (array_key_exists('quantity_and_stock_status', $data) && array_key_exists('value', $data['quantity_and_stock_status'])) {
            $data['quantity_and_stock_status']['value'] = $product->getQuantityAndStockStatus();
        }
        $data['stock'] = $this->getProductStock($product);
    }

    public function appendAttributes(&$data, $product, $storeId)
    {
        $productAttributes = $this->productAttributeManagement->getAttributes($product->getAttributeSetId());
        foreach ($productAttributes as $attribute) {
            $attrCode = $attribute->getAttributeCode();
            $attrData = $product->getData($attrCode);

            $label = $attribute->getDefaultFrontendLabel();
            $attrLabel = $attribute->getFrontendLabels();
            if (\count($attrLabel) > 0) {
                $store_label = current(array_filter($attrLabel, function ($l) use ($storeId) {
                    return $l->getStoreId() == $storeId;
                }));
                if ($store_label) {
                    $label = $store_label->getLabel();
                }
            }
            if ($attrData) {
                $name = null;
                $type = $attribute->getFrontendInput();
                if ($type === 'select') {
                    $name = $this->transform->toSelect($attrData, $attribute);
                } elseif ($type === 'boolean') {
                    $name = $this->transform->toBool($attrData);
                } elseif ($type === 'multiselect') {
                    $name = $this->transform->toMultiselect($attrData, $attribute);
                } elseif ($type === 'price') {
                    $name = $this->transform->toNullableFloat($attrData);
                } elseif ($type === 'date') {
                    $name = $attrData;
                } elseif ($type === 'weight') {
                    $name = $this->transform->toNullableFloat($attrData);
                }

                $data[$attrCode] = ['value' => $attrData];
                $has_additional_data = false;
                if (!is_null($label)) {
                    $data[$attrCode]['label'] = $label;
                    $has_additional_data = true;
                }
                if (!is_null($name) && $name != $attrData) {
                    $data[$attrCode]['name'] = $name;
                    $has_additional_data = true;
                }
                if (!$has_additional_data) {
                    $data[$attrCode] = $attrData;
                }
            }
        }
    }

    public function getProductStock($product)
    {
        try {
            return $this->stockItemRepository->get($product->getId())->getData();
        } catch (\Exception $exception) {
            $this->logger->debug(__('can\'t get stock for product %1, %2', $product->getId(), $exception->getMessage()), ['product', 'stock']);
        }
        return null;
    }
}
