<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Api\Constants;
use Wyvr\Core\Logger\Logger;
use Wyvr\Core\Service\ElasticClient;

class Page
{
    private const INDEX = 'page';

    public function __construct(
        protected ScopeConfigInterface    $scopeConfig,
        protected Logger                  $logger,
        protected PageRepositoryInterface $pageRepositoryInterface,
        protected StoreManagerInterface   $storeManager,
        protected ElasticClient           $elasticClient,
        protected SearchCriteriaBuilder   $searchCriteriaBuilder,
        protected FilterProvider          $templateProcessor,
        protected Transform               $transform,
        protected Clear                   $clear
    )
    {
    }

    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('no trigger name specified', ['page', 'update', 'all']);
            return;
        }
        $this->logger->measure($triggerName, ['page', 'update', 'all'], function () {
            $store_pages = [];
            array_map(function ($cms_page) use (&$store_pages) {
                $this->splitToStores($cms_page, $store_pages);
            }, $this->pageRepositoryInterface->getList($this->searchCriteriaBuilder->create())
                ->getItems());

            $this->updateStorePages($store_pages, true);
        });
    }

    public function updateSingle($id)
    {
        if (empty($id)) {
            $this->logger->error('can not update page because the id is not set', ['page', 'update']);
            return;
        }
        $cms_page = $this->pageRepositoryInterface->getById($id);
        $this->logger->measure(__('page id "%1"', $id), ['page', 'update'], function () use ($id, $cms_page) {
            $store_pages = [];
            $this->splitToStores($cms_page, $store_pages);
            $this->updateStorePages($store_pages, false);
        });
    }

    public function delete($id)
    {
        if (empty($id)) {
            $this->logger->error('can not delete page because the id is not set', ['category', 'delete']);
            return;
        }
        $cms_page = $this->pageRepositoryInterface->getById($id);
        $identifier = $cms_page->getIdentifier();

        $this->elasticClient->iterateStores(function ($store, $indexName) use ($id, $identifier) {
            $this->elasticClient->delete($indexName, $id);
            $this->clear->delete('page', $identifier);
        }, self::INDEX, Constants::PAGE_STRUC);
    }

    public function updatePage($page, $indexName): void
    {
        $id = $page->getId();
        if (empty($id)) {
            $this->logger->error('can not update page because the id is not set', ['category', 'update']);
            return;
        }
        $this->logger->debug('update page ' . $id, ['category', 'update']);

        $data = $this->transform->convertBoolAttributes($page->getData(), Constants::PAGE_BOOL_ATTRIBUTES);

        $search = $this->elasticClient->getSearchFromAttributes($this->scopeConfig->getValue(Constants::PAGE_INDEX_ATTRIBUTES), $page->getData());

        $this->elasticClient->update($indexName, [
            'id' => $id,
            'url' => strtolower($page->getIdentifier() ?? ''),
            'is_active' => $data['is_active'],
            'search' => $search,
            'page' => $data
        ]);
        $this->clear->upsert('page', $page->getIdentifier() ?? '');

    }

    public function splitToStores($cms_page, &$store_pages): void
    {
        foreach ($cms_page->getData('store_id') as $store) {
            if (!array_key_exists($store, $store_pages)) {
                $store_pages[$store] = [];
            }
            $store_pages[$store][] = $cms_page;
        }
    }

    public function updateStorePages($store_pages, $create_new = true): void
    {
        $this->elasticClient->iterateStores(function ($store, $indexName) use ($store_pages) {
            $store_id = $store->getId();
            $pages = array_merge($store_pages[0] ?? [], $store_pages[$store_id] ?? []);

            $this->logger->info(__('update %1 pages from store %2', count($pages), $store_id), ['block', 'update', 'store']);

            foreach ($pages as $page) {
                $this->updatePage($page, $indexName);
            }
        }, self::INDEX, Constants::PAGE_STRUC, $create_new);
    }

}
