<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Plugin;

use Wyvr\Core\Model\Product;

class ProductImportPlugin
{
    protected $product;

    public function __construct(
        Product $product
    ) {
        $this->product = $product;
    }

    public function afterImportSource(
        \Magento\ImportExport\Model\Import $subject,
        $result
    ) {
        if ($result && $subject->getEntity() == 'catalog_product') {
            while ($bunch = $subject->getDataSourceModel()->getNextBunch()) {
                foreach ($bunch as $rowNum => $rowData) {
                    if (array_key_exists('sku', $rowData)) {
                        $this->product->updateSingleBySku($rowData['sku']);
                    }
                }
            }
        }

        return $result;
    }
}
