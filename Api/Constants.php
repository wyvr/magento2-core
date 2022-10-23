<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Api;

class Constants
{
    const LOGGING_ENABLED = "wyvr/logging/enabled";
    const LOGGING_LEVEL = "wyvr/logging/level";


    const ELASTICSEARCH_PORT = 'catalog/search/elasticsearch7_server_port';
    const ELASTICSEARCH_HOST = 'catalog/search/elasticsearch7_server_hostname';

    const PRODUCT_INDEX_ATTRIBUTES = "wyvr/product/index_attributes";
    const PRODUCT_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ],
        'url' => [
            'type' => 'keyword',
            'index' => true
        ],
        'sku' => [
            'type' => 'keyword',
            'index' => true
        ],
        'search' => [
            'type' => 'text',
            'index' => true
        ],
        'product' => [
            'type' => 'object',
            'dynamic' => false
        ]
    ];

    const CATEGORY_INDEX_ATTRIBUTES = "wyvr/category/index_attributes";
    const CATEGORY_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ],
        'url' => [
            'type' => 'keyword',
            'index' => true
        ],
        'search' => [
            'type' => 'text',
            'index' => true
        ],
        'category' => [
            'type' => 'object',
            'dynamic' => false
        ]
    ];
}
