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

class Product
{
    private const INDEX = 'product';

    private const 

    /** @var ScopeConfigInterface */
    protected $scopeConfig;
    /** @var Logger */
    protected $logger;
    /** @var ProductCollectionFactory */
    protected $productCollectionFactory;
    /** @var StoreManagerInterface */
    protected $storeManager;
    /** @var LinkManagementInterface */
    protected $linkManagement;

    /** @var StockItemRepository */
    protected $stockItemRepository;

    /** @var ElasticClient */
    protected $elasticClient;

    private $ruleCache;

    public function __construct(
        ScopeConfigInterface                $scopeConfig,
        Logger                              $logger,
        ProductCollectionFactory            $productCollectionFactory,
        ProductAttributeManagementInterface $productAttributeManagement,
        RuleFactory                         $catalogRuleFactory,
        StoreManagerInterface               $storeManager,
        ElasticClient                       $elasticClient,
        LinkManagementInterface             $linkManagement,
        StockItemRepository                 $stockItemRepository,
        ProductRepository                   $productRepository
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productAttributeManagement = $productAttributeManagement;
        $this->catalogRuleFactory = $catalogRuleFactory;
        $this->storeManager = $storeManager;
        $this->elasticClient = $elasticClient;
        $this->linkManagement = $linkManagement;
        $this->stockItemRepository = $stockItemRepository;
        $this->productRepository = $productRepository;
    }

    public function updateSingle($id)
    {
        if (is_null($id)) {
            return;
        }
        $this->logger->measure('product update by id "' . $id . '"', function () use ($id) {
            $this->elasticClient->iterateStores(function ($store) use ($id) {
                $product = $this->productRepository->getById($id, false, $store->getId());

                $this->updateProduct($product, $store);
            }, self::INDEX, Constants::PRODUCT_STRUC);
        });
    }

    public function updateSingleBySku($sku)
    {
        if (is_null($sku)) {
            return;
        }
        $this->logger->measure('product update by sku "' . $sku . '"', function () use ($sku) {
            $this->elasticClient->iterateStores(function ($store) use ($sku) {
                $product = $this->productRepository->get($sku, false, $store->getId());

                $this->updateProduct($product, $store);
            }, self::INDEX, Constants::PRODUCT_STRUC);
        });
    }

    public function delete($id)
    {
        if (is_null($id)) {
            return;
        }
        $this->elasticClient->iterateStores(function () use ($id) {
            $this->elasticClient->delete($id);
        }, self::INDEX, Constants::PRODUCT_STRUC);
    }


    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('category updateAll No trigger name specified');
            return;
        }

        $this->logger->measure('product updateAll "' . $triggerName . '"', function () {
            $this->elasticClient->iterateStores(function ($store) {
                $products = $this->productCollectionFactory->create()
                    ->setStore($store)
                    ->getItems();

                $this->logger->info('updated ' . count($products) . ' products from store ' . $store->getId());

                foreach ($products as $p) {
                    $product = $this->productRepository->getById($p->getId(), false, $store->getId());
                    $this->updateProduct($product, $store);
                }
            }, self::INDEX, Constants::PRODUCT_STRUC);
        });
    }

    public function updateProduct($product, $store)
    {
        $id = $product->getEntityId();
        $storeId = $store->getId();
        if (is_null($id)) {
            $this->logger->error('can not update category because the id is not set');
            return;
        }
        $this->logger->debug('update product ' . $id);

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

        $this->elasticClient->update([
            'id' => $id,
            'url' => $product->getUrlKey(),
            'sku' => $product->getSku(),
            'search' => $search,
            'product' => $data
        ]);
    }

    public function getProductData($product, $storeId)
    {
        // get base data
        $data = $product->getData();
        $data['stock'] = $this->stockItemRepository->get($product->getId())->getData();
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
                    $name = $this->processSelect($attrData, $attribute);
                } elseif ($type === 'boolean') {
                    $name = $attrData === '1';
                } elseif ($type === 'multiselect') {
                    $name = $this->processMultiselect($attrData, $attribute);
                } elseif ($type === 'price') {
                    $name = $this->processNullableFloat($attrData);
                } elseif ($type === 'date') {
                    $name = $attrData;
                } elseif ($type === 'weight') {
                    $name = $this->processNullableFloat($attrData);
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

    private function processMultiselect($data, $attribute)
    {
        $splitValues = explode(',', $data);
        $label = "";
        foreach ($splitValues as $value) {
            $singleLabel = $this->processSelect($value, $attribute);
            if ($singleLabel) {
                $label .= $singleLabel . ', ';
            }
        }
        return trim(trim($label), ',');
    }

    private function processSelect($data, $attribute)
    {
        $option = array_filter($attribute->getOptions(), function ($value) use ($data) {
            return $value['value'] && $value['value'] == $data;
        });

        if (!$option || !is_array($option) || count($option) <= 0) {
            return '';
        }

        $label = reset($option)['label'];
        if (is_a($label, 'Magento\Framework\Phrase')) {
            return $label->getText();
        }
        return $label;
    }

    private function processNullableFloat($data)
    {
        if (is_null($data)) {
            return null;
        } else {
            return floatval($data);
        }
    }
}
