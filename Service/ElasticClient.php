<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Service;

use Wyvr\Core\Api\Constants;
use Wyvr\Core\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Elasticsearch\ClientBuilder;

class ElasticClient
{
    /** @var Logger */
    protected $logger;

    /** @var StoreManagerInterface */
    protected $storeManager;

    /** @var ClientBuilder */
    protected $elasticSearchClient;

    /** @var ScopeConfigInterface */
    protected $scopeConfig;

    /** @var string */
    protected $indexName;

    protected static $stores;

    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface  $scopeConfig,
        ClientBuilder         $elasticSearchClient,
        Logger                $logger,
    ) {
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;

        $builder = $elasticSearchClient;

        $elasticsearchHost = trim($this->scopeConfig->getValue(Constants::ELASTICSEARCH_HOST));
        $elasticsearchPort = trim($this->scopeConfig->getValue(Constants::ELASTICSEARCH_PORT));

        if ($elasticsearchHost) {
            $builder->setHosts([$elasticsearchHost . ':' . $elasticsearchPort ?: '9200']);
            $this->elasticSearchClient = $builder->build();
        } else {
            $this->elasticSearchClient = null;
        }


        if (is_null($this::$stores)) {
            $this::$stores = $this->storeManager->getStores();
        }
    }

    public function isValid()
    {
        if (!$this->isAvailable()) {
            return false;
        }
        if (is_null($this->indexName)) {
            $this->logger->critical('No indexname was given');
            return false;
        }
        return true;
    }

    /**
     * Sets the index name for elasticsearch queries
     * chainable method
     */
    public function setIndexName($indexName)
    {
        $this->indexName = $indexName;
        return $this;
    }

    public function createIndex($indexName, $mapping): bool
    {
        $indices = $this->elasticSearchClient->indices();
        $exists = $indices->exists(['index' => $indexName]);
        if (!$exists) {
            $indices->create(['index' => $indexName, 'body' => ['mappings' => ['properties' => $mapping]]]);
        }
        return $exists;
    }

    public function getSearchFromAttributes($attributes, $object)
    {
        if (!is_string($attributes) || !is_array($object) || count($object) == 0) {
            return '';
        }
        $result = array_filter(array_map(function ($entry) use ($object) {
            $attr = trim($entry);
            if (!array_key_exists($attr, $object)) {
                return null;
            }
            return $object[$attr];
        }, explode(',', $attributes)), function ($entry) {
            return !is_null($entry);
        });

        return json_encode($result);
    }

    public function remove(int $id): void
    {
        if (!$this->isValid()) {
            return;
        }
        if (!$id) {
            return;
        }

        $params = [
            'index' => $this->indexName,
            'id' => $id
        ];
        $exists = $this->elasticSearchClient->exists($params);
        if ($exists) {
            $this->elasticSearchClient->delete($params);
        }
    }

    public function update(array $data): void
    {
        if (!$this->isValid()) {
            return;
        }

        // only add when data is valid
        if (!array_key_exists('id', $data)) {
            return;
        }

        $indexName = $this->indexName;

        // create the indices when not existing
        if (!$this->elasticSearchClient->exists([
            'index' => $indexName
        ])) {
            $this->elasticSearchClient->indices()->create(['index' => $indexName]);
        }

        try {
            $this->elasticSearchClient->index([
                'index' => $indexName,
                'id' => $data['id'],
                'body' => $data
            ]);
        } catch (\Exception $exception) {
            $this->logger->error('error update ' . $data['id'] . ' => ' . $this->indexName . ' ' . $exception->getMessage());
            return;
        }
    }

    public function delete($id)
    {
        if (!$this->isValid()) {
            return;
        }

        try {
            $response = $this->elasticSearchClient->delete([
                'index' => $this->indexName,
                'id' => $id
            ]);
        } catch (\Exception $exception) {
            if ($exception->getCode() === 404) {
                $this->logger->error('error delete ' . $id . ' => ' . $this->indexName . ' does not exist');
                return;
                // the document does not exist
            }
            $this->logger->error('error delete ' . $id . ' => ' . $this->indexName . ' ' . $exception->getMessage());
        }
    }

    public function iterateStores(callable $callback, $index, $structure): void
    {
        if (is_null($callback)) {
            $this->logger->error('missing callback in iterateStores');
            return;
        }
        if (is_null($index)) {
            $this->logger->error('missing index in iterateStores');
            return;
        }
        foreach ($this::$stores as $store) {
            $store_id = $store->getId();
            $indexName = 'wyvr_' . $index . '_' . $store_id;
            $this->setIndexName($indexName);
            $this->createIndex($indexName, $structure);
            try {
                $callback($store, $indexName);
            } catch (\Exception $exception) {
                $this->logger->error('error in callback for store ' . $store_id . ' ' . $exception->getMessage());
            }
        }
    }

    /**
     * Save check whether elastic search is available or not
     * @return bool
     */
    private function isAvailable(): bool
    {
        try {
            if (!$this->elasticSearchClient || !$this->elasticSearchClient->ping()) {
                $this->logger->error('index ' . $this->indexName . ' is not available');
                return false;
            }
        } catch (\Exception $exception) {
            $this->logger->error('index ' . $this->indexName . ' is not available ' . $exception->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Destroys the index in elastic search
     * @return array|null
     */
    public function destroy()
    {
        $this->elasticSearchClient->indices()->delete(['index' => $this->indexName]);
    }
}
