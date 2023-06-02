<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Service;

use Magento\Store\Api\Data\StoreInterface;
use Wyvr\Core\Api\Constants;
use Wyvr\Core\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;

class ElasticClient
{
    protected Logger $logger;
    protected Client|null $elasticSearchClient;
    protected ScopeConfigInterface $scopeConfig;
    protected string $indexName;
    protected Store $store;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ClientBuilder        $clientBuilder,
        Logger               $logger,
        Store                $store
    )
    {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->store = $store;

        $elasticsearchHost = trim($this->scopeConfig->getValue(Constants::ELASTICSEARCH_HOST));
        $elasticsearchPort = trim($this->scopeConfig->getValue(Constants::ELASTICSEARCH_PORT));

        if ($elasticsearchHost) {
            $clientBuilder->setHosts([$elasticsearchHost . ':' . $elasticsearchPort ?: '9200']);
            $this->elasticSearchClient = $clientBuilder->build();
        } else {
            $this->elasticSearchClient = null;
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
    public function setIndexName($index_name)
    {
        $this->indexName = $index_name;
        return $this;
    }

    public function createIndex($index_name, $mapping): bool
    {
        $indices = $this->elasticSearchClient->indices();
        $exists = $indices->exists(['index' => $index_name]);
        if (!$exists) {
            $indices->create(['index' => $index_name, 'body' => ['mappings' => ['properties' => $mapping]]]);
        }
        return $exists;
    }

    public function getSearchFromAttributes($attributes, $object)
    {
        if (!is_string($attributes) || !is_array($object) || count($object) == 0) {
            return '';
        }
        $attributes = array_map(function ($entry) {
            return trim($entry);
        }, explode(',', $attributes));

        $result = array_map(function ($attr) use ($object) {
            if (!array_key_exists($attr, $object)) {
                return null;
            }
            if (is_array($object[$attr]) && array_key_exists('value', $object[$attr])) {
                return $object[$attr]['value'];
            }
            if(!is_array($object[$attr])) {
                return $object[$attr];
            }
            return json_encode($object[$attr]);
        }, $attributes);

        $filtered = array_filter($result, function ($entry) {
            return !empty($entry);
        });

        $normalized =  array_map(function ($entry) {
            return strip_tags(strtolower($entry));
        }, $filtered);

        return $normalized;
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

        $index_name = $this->indexName;

        $indices = $this->elasticSearchClient->indices();
        // create the indices when not existing
        if (!$indices->exists([
            'index' => $index_name
        ])) {
            $indices->create(['index' => $index_name]);
        }
        $this->setMarker('update', $data['id']);
        try {
            $this->elasticSearchClient->index([
                'index' => $index_name,
                'id' => $data['id'],
                'body' => $data
            ]);
        } catch (\Exception $exception) {
            $this->logger->error('error update ' . $data['id'] . ' => ' . $index_name . ' ' . $exception->getMessage());
            return;
        }
    }

    public function delete($id, $url)
    {
        if (!$this->isValid()) {
            return;
        }
        if (!$id) {
            return;
        }

        $this->setMarker('delete', $url);

        $params = [
            'index' => $this->indexName,
            'id' => $id
        ];

        $exists = $this->elasticSearchClient->exists($params);
        if ($exists) {
            try {
                $this->elasticSearchClient->delete($params);
            } catch (\Exception $exception) {
                if ($exception->getCode() === 404) {
                    $this->logger->error('error delete ' . $id . ' => ' . $this->indexName . ' does not exist');
                    return;
                    // the document does not exist
                }
                $this->logger->error('error delete ' . $id . ' => ' . $this->indexName . ' ' . $exception->getMessage());
            }
        }
    }

    public function iterateStores(callable $callback, $index, $structure, $create_new = false): void
    {
        if (is_null($index)) {
            $this->logger->error('missing index in iterateStores');
            return;
        }
        $this->store->iterate(function (StoreInterface $store) use ($callback, $index, $structure, $create_new) {
            $store_id = $store->getId();

            $alias = 'wyvr_' . $index . '_' . $store_id;
            $versions = $this->getVersions($alias, $create_new);
            $index_name = $alias . '_v' . $versions['version'];

            $this->setIndexName($index_name);
            $this->createIndex($index_name, $structure);
            try {
                $callback($store, $index_name);
            } catch (\Exception $exception) {
                $this->logger->error('error in callback for store ' . $store_id . ' ' . $exception->getMessage());
            }
            if ($create_new) {
                $this->updateAlias($alias, $index_name, $versions['prev_aliases'], $versions['all']);
            }
        });
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

    public function setMarker($action, $marker)
    {
        try {
            $fileName = './var/' . $this->indexName . '-' . $action;
            $fp = fopen($fileName, 'a+');
            fwrite($fp, $marker . "\n");
            fclose($fp);
        } catch (\Exception $exception) {
            $this->logger->error('error create marker in ' . $fileName . ' ' . $exception->getMessage());
        }
    }

    public function getIndexData($indexName, $query = null)
    {
        $search = ['index' => $indexName];
        if (is_null($query)) {
            $search['q'] = [
                'match_all' => []
            ];
            $search['size'] = 10000;
            //@TODO implement scrolling
        }
        $result = $this->elasticSearchClient->search($search);
        if (!array_key_exists('hits', $result) || !array_key_exists('hits', $result['hits'])) {
            return [];
        }
        return $result['hits']['hits'];
    }

    public function getVersions($index_name, $create_new = false): array
    {
        $result = [ 'version' => 1, 'prev_aliases' => null, 'all' => null];
        if (!$index_name) {
            return $result;
        }
        try {
            $previous_alias_versions = array_keys($this->elasticSearchClient->indices()->getAlias(['index' => $index_name]));
            if (count($previous_alias_versions) > 0) {
                $result['prev_aliases'] = $previous_alias_versions;
            }
        } catch (\Exception $exception) {
            $this->logger->debug('can not get previous version of ' . $index_name . ' ' . $exception->getMessage());
        }
        try {
            $all_indices = [];
            $versions = array_map(function ($index) use (&$all_indices) {
                $all_indices[] = $index;
                return intval(preg_replace("/^.*?_v(\d+)$/", "$1", $index));
            }, array_keys($this->elasticSearchClient->indices()->get(['index' => $index_name . '_v*'])));
            if (count($versions) == 0) {
                return $result;
            }
            rsort($versions);
            $result['version'] = $versions[0];
            if ($create_new) {
                $result['version'] = $result['version'] + 1;
            }
            rsort($all_indices);
            $result['all'] = $all_indices;
        } catch (\Exception $exception) {
            $this->logger->debug('can not get version of ' . $index_name . ' ' . $exception->getMessage());
        }
        return $result;
    }

    public function updateAlias($alias, $index_name, $previous_index_names, $delete_index_names)
    {
        $data = ['body' => [
            'actions' => [
                [
                    'add' => [
                        'index' => $index_name,
                        'alias' => $alias
                    ]
                ]
            ]
        ]];
        if (is_array($previous_index_names)) {
            foreach ($previous_index_names as $previous_index_name) {
                $data['body']['actions'][] = ['remove' => [
                    'index' => $previous_index_name,
                    'alias' => $alias
                ]];
            }
        }
        $this->elasticSearchClient->indices()->updateAliases($data);

        if (is_array($delete_index_names)) {
            foreach ($delete_index_names as $delete_index_name) {
                $this->elasticSearchClient->indices()->delete(['index' => $delete_index_name]);
            }
        }
    }
}
