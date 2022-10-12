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
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\CatalogRule\Model\RuleFactory;
use Wyvr\Core\Logger\Logger;
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
        ProductAttributeManagementInterface $productAttributeManagement,
        RuleFactory $catalogRuleFactory,
        StoreManagerInterface     $storeManager,
        ClientBuilder             $elasticSearchClient,
        ElasticClient             $elasticClient
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productAttributeManagement = $productAttributeManagement;
        $this->catalogRuleFactory = $catalogRuleFactory;
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
        if (is_null($id)) {
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
        if (is_null($id)) {
            $this->logger->error('can not update category because the id is not set');
            return;
        }
        // get base data
        $data = $product->getData();
        // add the categories
        $data['category_ids'] = $product->getCategoryIds();
        // extend the attributes
        $this->appendAttributes($data, $product);
        $this->appendPrice($data, $product);

        // convert known attributes to bool
        //        foreach (['is_active', 'is_anchor', 'include_in_menu'] as $attr) {
        //            if (array_key_exists($attr, $data)) {
        //                $data[$attr] = $data[$attr] === '1';
        //            }
        //        }
        $this->elasticClient->update([
            'id' => $id,
            'url' => $product->getUrlKey(),
            'sku' => $product->getSku(),
            'product' => json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR)
        ]);
    }

    public function appendPrice(&$data, &$product) {
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

    public function appendAttributes(&$data, $product)
    {
        $productAttributes = $this->productAttributeManagement->getAttributes($product->getAttributeSetId());
        foreach ($productAttributes as $attribute) {
            $attrCode = $attribute->getAttributeCode();
            $attrData = $product->getData($attrCode);
            $value = null;

            if ($attrData) {
                $data[$attrCode] = ['value' => $attrData];
                $label = '';
                if ($attribute->getFrontendInput() === 'select') {
                    $label = $this->processSelect($attrData, $attribute);
                } elseif ($attribute->getFrontendInput() === 'boolean') {
                    $label = $attrData === '1';
                } elseif ($attribute->getFrontendInput() === 'multiselect') {
                    $label = $this->processMultiselect($attrData, $attribute);
                } elseif ($attribute->getFrontendInput() === 'price') {
                    $label = $this->processNullableFloat($attrData);
                } elseif ($attribute->getFrontendInput() === 'date') {
                    $label = $attrData;
                } elseif ($attribute->getFrontendInput() === 'weight') {
                    $label = $this->processNullableFloat($attrData);
                } else {
                    $label = $attrData;
                }
                $data[$attrCode]['label'] = $label;
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
