<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\ConfigurableProduct\Api\LinkManagementInterface;
use Magento\CatalogInventory\Model\Stock\StockItemRepository;
use Wyvr\Core\Api\Constants;
use Wyvr\Core\Logger\Logger;
use Wyvr\Core\Service\ElasticClient;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableProduct;

class Product
{
    private const INDEX = 'product';

    private string $indexName;

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
        protected Clear                               $clear
    )
    {
        $this->indexName = 'wyvr_' . self::INDEX;
    }

    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('no trigger name specified', ['product', 'update', 'all']);
            return;
        }

        $this->logger->measure($triggerName, ['product', 'update', 'all'], function () {
            $this->elasticClient->iterateStores(function ($store) {
                $products = $this->productCollectionFactory->create()
                    ->setStore($store)
                    ->getItems();

                $this->logger->info(__('update %1 products from store %2', count($products), $store->getId()), ['product', 'update', 'all']);

                foreach ($products as $p) {
                    $product = $this->productRepository->getById($p->getId(), false, $store->getId());
                    $this->updateProduct($product, $store);
                }
            }, self::INDEX, Constants::PRODUCT_STRUC, true);
        });
    }

    public function updateSingle($id, $prev_category_ids = [])
    {
        if (empty($id)) {
            $this->logger->error('can not update product because the id is not set', ['product', 'update']);
            return;
        }
        $this->logger->measure(__('product id "%1"', $id), ['product', 'update'], function () use ($id, $prev_category_ids) {
            $affected_category_ids = [];

            $this->elasticClient->iterateStores(function ($store) use ($id, $prev_category_ids, &$affected_category_ids) {
                $product = $this->productRepository->getById($id, false, $store->getId());
                if (is_array($prev_category_ids) && count($prev_category_ids)) {
                    $category_ids = $product->getCategoryIds();
                    $affected_category_ids = array_merge($affected_category_ids, array_diff($category_ids, $prev_category_ids), array_diff($prev_category_ids, $category_ids));
                }
                $this->updateProduct($product, $store);
            }, self::INDEX, Constants::PRODUCT_STRUC);

            $affected_category_ids = array_unique($affected_category_ids);
            if (count($affected_category_ids) > 0) {
                $this->logger->info(__('affected category ids %1', join(',', $affected_category_ids)), ['product', 'update']);
                foreach ($affected_category_ids as $id) {
                    $this->category->updateSingle($id);
                }
            }
        });
    }

    public function updateSingleBySku($sku)
    {
        if (empty($sku)) {
            $this->logger->error('can not update product because the sku is not set', ['product', 'update']);
            return;
        }
        $this->logger->measure(__('product sku "%1"', $sku), ['product', 'update'], function () use ($sku) {
            $this->elasticClient->iterateStores(function ($store) use ($sku) {
                $product = $this->productRepository->get($sku, false, $store->getId());

                $this->updateProduct($product, $store);
            }, self::INDEX, Constants::PRODUCT_STRUC);
        });
    }

    public function delete($id)
    {
        if (empty($id)) {
            $this->logger->error('can not delete product because the id is not set', ['product', 'delete']);
            return;
        }
        $this->elasticClient->iterateStores(function ($store) use ($id) {
            $product = $this->productRepository->getById($id, false, $store->getId());
            $this->elasticClient->delete($this->elasticClient->getIndexName($this->indexName, $store), $id);
            $this->clear->delete('product', $product->getUrlKey());

        }, self::INDEX, Constants::PRODUCT_STRUC);
    }

    public function updateProduct($product, $store)
    {
        $id = $product->getEntityId();
        $storeId = $store->getId();
        if (empty($id)) {
            $this->logger->error('can not update product because the id is not set', ['product', 'update']);
            return;
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

        $this->elasticClient->update($this->elasticClient->getIndexName($this->indexName, $storeId), [
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
        $this->clear->upsert('product', $url);

        if ($product->getTypeId() == 'simple') {

            // Get all parent ids of this product
            $parentIds = $this->configurableProduct->getParentIdsByChild($id);

            if (!empty($parentIds)) {
                // This means that the simple product is associated with a configurable product, load it
                $configurableProduct = $this->productRepository->getById($parentIds[0]);
                $this->logger->debug(__('update configurable product %1', $parentIds[0]), ['product', 'update']);
                $this->updateProduct($configurableProduct, $store);
            }
        }

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
            return $this->getProductData($p, $storeId);
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
            if (!empty($attrLabel)) {
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
