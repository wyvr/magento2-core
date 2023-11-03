<?php

namespace Wyvr\Core\Observer\Cobby;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Wyvr\Core\Logger\Logger;
use Wyvr\Core\Model\Product;

class ProductImportAfter implements ObserverInterface
{
    public function __construct(
        protected Logger  $logger,
        protected Product $product
    )
    {
    }

    public function execute(Observer $observer)
    {
        $transportObject = $observer->getData('transport');
        $transportData = $transportObject->getData();
        try {
            if ($transportData && array_key_exists('rows', $transportData)) {
                /*
                 {
                  "rows": [
                    {
                      "sku": "124900004",
                      "product_type": "simple",
                      "attribute_set": "Diverse Taschen",
                      "entity_id": 21508,
                      "websites": [],
                      "attributes": [{ "store_id": "0", "special_price": "" }]
                    },
                    {
                      "sku": "291090015",
                      "product_type": "simple",
                      "attribute_set": "Diverse Taschen",
                      "entity_id": 25491,
                      "websites": [],
                      "attributes": [{ "store_id": "0", "special_price": "" }]
                    }
                  ],
                  "type_models": ["simple"],
                  "used_skus": ["124900004", "291090015"]
                }
                 */
                foreach ($transportData["rows"] as $row) {
                    if (array_key_exists('entity_id', $row)) {
                        $this->product->updateSingle($row['entity_id']);
                    }
                }

                $this->logger->info('cobby after import: ' . \json_encode($transportData));
            }
        } catch (\Exception $exception) {
            $this->logger->error('cobby after import error: ' . $exception->getMessage());
        }
    }
}
