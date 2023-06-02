<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Wyvr\Core\Api\Constants;
use Wyvr\Core\Logger\Logger;
use Wyvr\Core\Service\ElasticClient;

class Block
{
    private const INDEX = 'block';

    protected ScopeConfigInterface $scopeConfig;
    private Logger $logger;
    protected BlockRepositoryInterface $blockRepositoryInterface;
    protected StoreManagerInterface $storeManager;
    protected ElasticClient $elasticClient;
    protected FilterProvider $templateProcessor;

    public function __construct(
        ScopeConfigInterface    $scopeConfig,
        Logger                  $logger,
        BlockRepositoryInterface $blockRepositoryInterface,
        StoreManagerInterface   $storeManager,
        ElasticClient           $elasticClient,
        SearchCriteriaBuilder   $searchCriteriaBuilder,
        FilterProvider          $templateProcessor
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->blockRepositoryInterface = $blockRepositoryInterface;
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
        $cms_block = $this->blockRepositoryInterface->getById($id);
        $this->logger->measure('block update by id "' . $id . '"', function () use ($id, $cms_block) {
            $store_blocks = [];
            $this->splitToStores($cms_block, $store_blocks);
            $this->updateStoreBlocks($store_blocks, false);
        });
    }

    public function delete($id)
    {
        if (empty($id)) {
            return;
        }
        $cms_page = $this->blockRepositoryInterface->getById($id);
        $identifier = $cms_page->getIdentifier();

        $this->elasticClient->iterateStores(function ($store) use ($id, $identifier) {
            $this->elasticClient->delete($id, $identifier);
        }, self::INDEX, Constants::BLOCK_STRUC);
    }

    public function updateAll($triggerName)
    {
        if (empty($triggerName)) {
            $this->logger->error('block updateAll No trigger name specified');
            return;
        }
        $blocks = $this->blockRepositoryInterface->getList($this->searchCriteriaBuilder->create())
            ->getItems();
        $this->logger->measure('block updateAll "' . $triggerName . '"', function () {
            $store_blocks = [];
            array_map(function ($cms_block) use (&$store_blocks) {
                $this->splitToStores($cms_block, $store_blocks);
            }, $this->blockRepositoryInterface->getList($this->searchCriteriaBuilder->create())
                ->getItems());

            $this->updateStoreBlocks($store_blocks, true);
        });
    }

    public function updateBlock($block): void
    {
        $id = $block->getId();
        if (empty($id)) {
            $this->logger->error('can not update block because the id is not set');
            return;
        }
        $this->logger->debug('update block ' . $id);

        $data = $block->getData();
        //->getBlockFilter()
        /*$data['rendered'] = $this->templateProcessor->getblockFilter()
            ->setStoreId($store_id)
            ->filter($data['content']);
        */
        $this->elasticClient->update([
            'id' => $id,
            'identifier' => strtolower($block->getIdentifier() ?? ''),
            'is_active' => $block->getIsActive() === '1',
            'block' => $data
        ]);
    }

    public function splitToStores($cms_block, &$store_blocks): void
    {
        foreach ($cms_block->getData('store_id') as $store) {
            if (!array_key_exists($store, $store_blocks)) {
                $store_blocks[$store] = [];
            }
            $store_blocks[$store][] = $cms_block;
        }
    }

    public function updateStoreBlocks($store_blocks, $create_new = true): void
    {
        $this->elasticClient->iterateStores(function ($store) use ($store_blocks) {
            $store_id = $store->getId();
            $blocks = array_merge($store_blocks[0] ?? [], $store_blocks[$store_id] ?? []);

            $this->logger->info('updated ' . count($blocks) . ' blocks from store ' . $store_id);

            foreach ($blocks as $block) {
                $this->updateBlock($block);
            }
        }, self::INDEX, Constants::BLOCK_STRUC, $create_new);
    }

}
