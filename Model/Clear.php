<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2023 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model;

use Elasticsearch\ClientBuilder;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Wyvr\Core\Logger\Logger;
use Wyvr\Core\Api\Constants;
use Wyvr\Core\Service\ElasticClient;

class Clear
{
    private const INDEX = 'clear';
    private string $indexName;

    public function __construct(
        protected ScopeConfigInterface $scopeConfig,
        protected Logger               $logger,
        protected ElasticClient        $elasticClient,
    ) {
        $this->indexName = 'wyvr_' . self::INDEX;
    }

    public function upsert(string $scope, string $url)
    {
        $this->set($scope, $url, 'upsert');
    }

    public function delete(string $scope, string $url)
    {
        $this->set($scope, $url, 'delete');
    }

    public function set(string $scope, string $url, string $type)
    {
        if (!$scope || !$url || !$type) {
            return;
        }
        try {
            $this->elasticClient->createIndex($this->indexName, Constants::CLEAR_STRUC);
            $this->elasticClient->update($this->indexName, ['scope' => $scope, 'id' => $url, 'type' => $type]);
        } catch (\Exception $exception) {
            $this->logger->error(__('error %1 %2 => %3 %4', $type, $url, $this->indexName, $exception->getMessage()));
            return;
        }
    }
    public function all(string $triggerName)
    {
        if (!$triggerName) {
            return;
        }
        $this->logger->warning(__('clear all caches because of %1', $triggerName));
        $this->set('*', '*', 'clear');
    }
}
