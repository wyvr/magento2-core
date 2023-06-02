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

    protected ScopeConfigInterface $scopeConfig;
    private Logger $logger;
    protected PageRepositoryInterface $pageRepositoryInterface;
    protected StoreManagerInterface $storeManager;
    protected ElasticClient $elasticClient;
    protected FilterProvider $templateProcessor;

    public function __construct(
        ScopeConfigInterface    $scopeConfig,
        Logger                  $logger,
        PageRepositoryInterface $pageRepositoryInterface,
        StoreManagerInterface   $storeManager,
        ElasticClient           $elasticClient,
        SearchCriteriaBuilder   $searchCriteriaBuilder,
        FilterProvider          $templateProcessor
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->pageRepositoryInterface = $pageRepositoryInterface;
        $this->storeManager = $storeManager;
        $this->elasticClient = $elasticClient;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->templateProcessor = $templateProcessor;
    }

    public function updateSingle($id)
    {
        if (empty($id)) {
            return;
        }
        $cms_page = $this->pageRepositoryInterface->getById($id);
        $this->logger->measure('page update by id "' . $id . '"', function () use ($id, $cms_page) {
            $store_pages = [];
            $this->splitToStores($cms_page, $store_pages);
            $this->updateStorePages($store_pages, false);
        });
    }

    public function delete($id)
    {
        if (empty($id)) {
            return;
        }
        $cms_page = $this->pageRepositoryInterface->getById($id);
        $identifier = $cms_page->getIdentifier();

        $this->elasticClient->iterateStores(function ($store) use ($id, $identifier) {
            $this->elasticClient->delete($id, $identifier);
        }, self::INDEX, Constants::PAGE_STRUC);
    }

    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('page updateAll No trigger name specified');
            return;
        }
        $this->logger->measure('pages updateAll "' . $triggerName . '"', function () {
            $store_pages = [];
            array_map(function ($cms_page) use (&$store_pages) {
                $this->splitToStores($cms_page, $store_pages);
            }, $this->pageRepositoryInterface->getList($this->searchCriteriaBuilder->create())
                ->getItems());

            $this->updateStorePages($store_pages, true);
        });
    }

    public function updatePage($page): void
    {
        $id = $page->getId();
        if (empty($id)) {
            $this->logger->error('can not update page because the id is not set');
            return;
        }
        $this->logger->debug('update page ' . $id);

        $data = $page->getData();
        //->getBlockFilter()
        /*$data['rendered'] = $this->templateProcessor->getPageFilter()
            ->setStoreId($store_id)
            ->filter($data['content']);
        */
        $search = $this->elasticClient->getSearchFromAttributes($this->scopeConfig->getValue(Constants::PAGE_INDEX_ATTRIBUTES), $data);
        $this->elasticClient->update([
            'id' => $id,
            'url' => strtolower($page->getIdentifier() ?? ''),
            'is_active' => $page->getIsActive() === '1',
            'search' => $search,
            'page' => $data
        ]);
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
        $this->elasticClient->iterateStores(function ($store) use ($store_pages) {
            $store_id = $store->getId();
            $pages = array_merge($store_pages[0] ?? [], $store_pages[$store_id] ?? []);

            $this->logger->info('updated ' . count($pages) . ' pages from store ' . $store_id);

            foreach ($pages as $page) {
                $this->updatePage($page);
            }
        }, self::INDEX, Constants::PAGE_STRUC, $create_new);
    }

}
