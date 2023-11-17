<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
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
        protected ManagerInterface                    $eventManager
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
                    $this->updateProduct($product, $store, $indexName, false);
                    $current++;
                }
            }, self::INDEX, Constants::PRODUCT_STRUC, true);
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

    public function updateStock($id)
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
                if (array_key_exists('quantity_and_stock_status', $data['product']) && array_key_exists('value', $data['product']['quantity_and_stock_status'])) {
                    $data['product']['quantity_and_stock_status']['value'] = $product->getQuantityAndStockStatus();
                }
                $data['product']['stock'] = null;
                try {
                    $data['product']['stock'] = $this->stockItemRepository->get($product->getId())->getData();
                } catch (\Exception $exception) {
                    $this->logger->debug(__('can\'t get stock for product %1, %2', $product->getId(), $exception->getMessage()), ['product', 'stock']);
                }

                $this->elasticClient->update($indexName, $data);

                $this->clear->upsert('product', $data['url']);

                $this->logger->info(__('update stock of id "%1"', $id), ['stock', 'update']);
            }, self::INDEX, Constants::PRODUCT_STRUC);
        });
    }

    public function updatePriceBySku($sku)
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

                $this->logger->info(__('update price of id "%1"', $id), ['price', 'update']);
            }, self::INDEX, Constants::PRODUCT_STRUC);
        });
    }

    public function updateProduct($product, $store, string $indexName, ?bool $partial_import = true): array
    {
        $id = $product->getEntityId();
        $storeId = $store->getId();
        if (empty($id)) {
            $this->logger->error('can not update product because the id is not set', ['product', 'update']);
            return [];
        }
        $category_ids = $product->getCategoryIds();
        // check if the product has to be updated, to avoid multiple updates in series
        if ($partial_import) {
            $data = $this->elasticClient->getById($indexName, $id);
            if ($data && $data['updated_at'] === $product->getUpdatedAt()) {
                // product has not been changed, ignore
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


        $parentProducts = [];
        $parentProductsFull = [];
        // not required in a full reindex, because the configurable also gets processed
        if ($product->getTypeId() == 'simple' && $partial_import) {
            // Get all parent ids of this product
            $parentIds = $this->configurableProduct->getParentIdsByChild($id);
            if (\count($parentIds) > 0) {
                // This means that the simple product is associated with a configurable product, load it
                foreach ($parentIds as $parentId) {
                    $configurableProduct = $this->productRepository->getById($parentId);
                    // ignore disabled parents
                    if ($configurableProduct->getStatus() == Status::STATUS_DISABLED) {
                        continue;
                    }
                    $parentProductsFull[] = $configurableProduct;
                    $parent = [
                        'id' => $parentId,
                        'sku' => $configurableProduct->getSku(),
                        'url_key' => $configurableProduct->getUrlKey(),
                    ];

                    $parentProducts[] = $parent;
                }
            }
        }
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

        if (\count($parentProductsFull) > 0) {
            foreach ($parentProductsFull as $parentProduct) {
                $this->logger->debug(__('update configurable product %1', $parentProduct->getId()), ['product', 'update']);
                $configurable_category_ids = $this->updateProduct($parentProduct, $store, $indexName, $partial_import);
                $category_ids = \array_merge($category_ids, $configurable_category_ids);
            }
        }
        // dispatch event to allow easy react to updates
        $this->eventManager->dispatch(Constants::EVENT_PRODUCT_UPDATE_AFTER, ['product' => $product, 'category_ids' => $category_ids, 'store_id' => $storeId]);
        return $category_ids;
    }

    public function getProductData($product, $storeId)
    {
        // get base data
        $data = $product->getData();
        $data['stock'] = null;
        try {
            $data['stock'] = $this->stockItemRepository->get($product->getId())->getData();
        } catch (\Exception $exception) {
            $this->logger->debug(__('can\'t get stock for product %1, %2', $product->getId(), $exception->getMessage()), ['product', 'stock']);
        }
        // add the categories
        $data['category_ids'] = $product->getCategoryIds();
        // extend the attributes
        $this->appendAttributes($data, $product, $storeId);
        $this->appendPrice($data, $product);
        $this->appendConfigurables($data, $product, $storeId);
        return $data;
    }

    public function appendConfigurables(&$data, $product, $storeId)
    {
        if ($product->getTypeId() !== 'configurable') {
            return;
        }
        $instance = $product->getTypeInstance();
        $data['configurable_products'] = array_map(function ($p) use ($storeId) {
            // @NOTE getUsedProducts returns only a subset of all available product attributes, avoid duplicating data, the simples to the cionfigurables has to be loaded on the client
            return $p->getId();
        }, $instance->getUsedProducts($product));
        $data['configurable_options'] = $instance->getConfigurableOptions($product);
    }

    public function appendPrice(&$data, $product)
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
}
