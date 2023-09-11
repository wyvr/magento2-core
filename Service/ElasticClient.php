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
    protected Store $store;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ClientBuilder        $clientBuilder,
        Logger               $logger,
        Store                $store
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->store = $store;

        $elasticsearchHost = \trim($this->scopeConfig->getValue(Constants::ELASTICSEARCH_HOST));
        $elasticsearchPort = \trim($this->scopeConfig->getValue(Constants::ELASTICSEARCH_PORT));

        if ($elasticsearchHost) {
            $clientBuilder->setHosts([$elasticsearchHost . ':' . $elasticsearchPort ?: '9200']);
            $this->elasticSearchClient = $clientBuilder->build();
        } else {
            $this->elasticSearchClient = null;
        }
    }

    public function isValid(string $indexName)
    {
        if (!$this->isAvailable($indexName)) {
            return false;
        }
        return true;
    }

    public function createIndex(string $indexName, $mapping): bool
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
        if (!\is_string($attributes) || !\is_array($object) || \count($object) == 0) {
            return '';
        }
        $attributes = \array_map(function ($entry) {
            return \trim($entry);
        }, \explode(',', $attributes));

        $result = \array_map(function ($attr) use ($object) {
            if (!\array_key_exists($attr, $object)) {
                return null;
            }
            if (\is_array($object[$attr]) && \array_key_exists('value', $object[$attr])) {
                return $object[$attr]['value'];
            }
            if (!\is_array($object[$attr])) {
                return $object[$attr];
            }
            return \json_encode($object[$attr]);
        }, $attributes);

        $filtered = \array_filter($result, function ($entry) {
            return !empty($entry);
        });

        $normalized = array_map(function ($entry) {
            return strip_tags(strtolower($entry));
        }, $filtered);

        return $normalized;
    }

    public function update(string $indexName, array $data): void
    {
        if (!$this->isValid($indexName)) {
            return;
        }

        // only add when data is valid
        if (!array_key_exists('id', $data)) {
            return;
        }

        $indices = $this->elasticSearchClient->indices();
        // create the indices when not existing
        if (!$indices->exists([
            'index' => $indexName
        ])) {
            $indices->create(['index' => $indexName]);
        }
        try {
            $this->elasticSearchClient->index([
                'index' => $indexName,
                'id' => $data['id'],
                'body' => $data
            ]);
        } catch (\Exception $exception) {
            $this->logger->error('error update ' . $data['id'] . ' => ' . $indexName . ' ' . $exception->getMessage());
            return;
        }
    }

    public function delete(string $indexName, $id)
    {
        if (!$this->isValid($indexName)) {
            return;
        }
        if (!$id) {
            return;
        }

        $params = [
            'index' => $indexName,
            'id' => $id
        ];

        $exists = $this->elasticSearchClient->exists($params);
        if ($exists) {
            try {
                $this->elasticSearchClient->delete($params);
            } catch (\Exception $exception) {
                if ($exception->getCode() === 404) {
                    $this->logger->error('error delete ' . $id . ' => ' . $indexName . ' does not exist');
                    return;
                    // the document does not exist
                }
                $this->logger->error('error delete ' . $id . ' => ' . $indexName . ' ' . $exception->getMessage());
            }
        }
    }

    public function iterateStores(callable $callback, $indexName, $structure, $create_new = false): void
    {
        if (\is_null($indexName)) {
            $this->logger->error('missing index in iterateStores');
            return;
        }
        $this->store->iterate(function (StoreInterface $store) use ($callback, $indexName, $structure, $create_new) {
            $store_id = $store->getId();

            $alias = 'wyvr_' . $indexName . '_' . $store_id;
            $versions = $this->getVersions($alias, $create_new);
            $index_name = $alias . '_v' . $versions['version'];

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
     * @param string $indexName
     * @return bool
     */
    private function isAvailable(string $indexName): bool
    {
        try {
            if (!$this->elasticSearchClient || !$this->elasticSearchClient->ping()) {
                $this->logger->error('index ' . $indexName . ' is not available');
                return false;
            }
        } catch (\Exception $exception) {
            $this->logger->error('index ' . $indexName . ' is not available ' . $exception->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Destroys the index in elastic search
     * @return array|null
     */
    public function destroy(string $indexName)
    {
        $this->elasticSearchClient->indices()->delete(['index' => $indexName]);
    }

    public function getIndexData(string $indexName, $query = null)
    {
        $search = ['index' => $indexName];
        if (\is_null($query)) {
            $search['q'] = [
                'match_all' => []
            ];
            $search['size'] = 10000;
            //@TODO implement scrolling
        }
        $result = $this->elasticSearchClient->search($search);
        if (!\array_key_exists('hits', $result) || !\array_key_exists('hits', $result['hits'])) {
            return [];
        }
        return $result['hits']['hits'];
    }

    public function getVersions(string $indexName, $create_new = false): array
    {
        $result = ['version' => 1, 'prev_aliases' => null, 'all' => null];
        if (!$indexName) {
            return $result;
        }
        try {
            $previous_alias_versions = array_keys($this->elasticSearchClient->indices()->getAlias(['index' => $indexName]));
            if (\count($previous_alias_versions) > 0) {
                $result['prev_aliases'] = $previous_alias_versions;
            }
        } catch (\Exception $exception) {
            $this->logger->debug('can not get previous version of ' . $indexName . ' ' . $exception->getMessage());
        }
        try {
            $all_indices = [];
            $versions = \array_map(function ($index) use (&$all_indices) {
                $all_indices[] = $index;
                return intval(preg_replace("/^.*?_v(\d+)$/", "$1", $index));
            }, \array_keys($this->elasticSearchClient->indices()->get(['index' => $indexName . '_v*'])));
            if (count($versions) == 0) {
                return $result;
            }
            \rsort($versions);
            $result['version'] = $versions[0];
            if ($create_new) {
                $result['version'] = $result['version'] + 1;
            }
            \rsort($all_indices);
            $result['all'] = $all_indices;
        } catch (\Exception $exception) {
            $this->logger->debug('can not get version of ' . $indexName . ' ' . $exception->getMessage());
        }
        return $result;
    }

    public function updateAlias(string $alias, string $indexName, array $previousIndexNames, array $deleteIndexNames)
    {
        $data = ['body' => [
            'actions' => [
                [
                    'add' => [
                        'index' => $indexName,
                        'alias' => $alias
                    ]
                ]
            ]
        ]];
        if (\is_array($previousIndexNames)) {
            foreach ($previousIndexNames as $previousIndexName) {
                $data['body']['actions'][] = ['remove' => [
                    'index' => $previousIndexName,
                    'alias' => $alias
                ]];
            }
        }
        $this->elasticSearchClient->indices()->updateAliases($data);

        if (\is_array($deleteIndexNames)) {
            foreach ($deleteIndexNames as $deleteIndexName) {
                $this->elasticSearchClient->indices()->delete(['index' => $deleteIndexName]);
            }
        }
    }

    public function getIndexName(string $indexName, string|int|null $store): string
    {
        if ($store) {
            return $indexName . '_' . $store;
        }
        return $indexName;
    }
}
