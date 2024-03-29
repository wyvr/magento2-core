<?php

namespace Wyvr\Core\Console\Command;

use Magento\Catalog\Model\ProductRepository;
use Magento\Store\Api\Data\StoreInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Wyvr\Core\Model\Product;
use Wyvr\Core\Service\Store;
use Magento\Framework\App\State;

class UpdateSingleProduct extends Command
{

    public function __construct(
        protected Product $product,
        protected ProductRepository $productRepository,
        protected Store $store,
        protected State $state
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('wyvr:product:update')
            ->setDescription('Update a single product')
            ->addArgument('id', InputArgument::REQUIRED, 'Product ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // required for frontend related modules that can break when appstate is not set
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);

        $id = $input->getArgument('id');

        // validate the id
        $found = false;
        $sku = null;
        $this->store->iterate(function (StoreInterface $store) use ($id, $output, &$found, &$sku) {
            $product = $this->productRepository->getById($id, false, $store->getId());
            if ($product) {
                $sku = $product->getSku();
                $found = true;
                $output->writeln(__('found in store %1', $store->getId()));
            }
        });
        if (!$found) {
            $output->writeln(__('Product with id %1 could not be found', $id));
            return;
        }
        $this->product->updateSingle($id);
        $output->writeln(__('Product %1 (sku %2) updated successfully.', $id, $sku));
    }
}
