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

    const STORES_IGNORED = "wyvr/stores/ignored";

    const PRODUCT_INDEX_ATTRIBUTES = "wyvr/product/index_attributes";
    const PRODUCT_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ],
        'url' => [
            'type' => 'keyword',
            'index' => true,
        ],
        'sku' => [
            'type' => 'keyword',
            'index' => true,
        ],
        'name' => [
            'type' => 'keyword',
            'index' => true,
        ],
        'visibility' => [
            'type' => 'integer',
            'index' => true
        ],
        'search' => [
            'type' => 'text',
            'index' => true,
        ],
        'product' => [
            'type' => 'object',
            'dynamic' => false
        ],
        'created_at' => [
            'type' => 'date',
            'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis'
        ],
        'updated_at' => [
            'type' => 'date',
            'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis'
        ]
    ];


    const PARENT_PRODUCTS_NAME = 'wyvr_parent_products';
    const PARENT_PRODUCTS_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ],
        'type_id' => [
            'type' => 'keyword',
            'index' => false
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
            'index' => true,
        ],
        'name' => [
            'type' => 'keyword',
            'index' => true,
        ],
        'is_active' => [
            'type' => 'boolean',
            'index' => true,
        ],
        'search' => [
            'type' => 'text',
            'index' => true,
        ],
        'category' => [
            'type' => 'object',
            'dynamic' => false
        ]
    ];
    const CATEGORY_BOOL_ATTRIBUTES = ['is_active', 'is_anchor', 'include_in_menu'];
    const PAGE_INDEX_ATTRIBUTES = "wyvr/page/index_attributes";
    const PAGE_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ],
        'url' => [
            'type' => 'keyword',
            'index' => true,
        ],
        'is_active' => [
            'type' => 'boolean',
            'index' => true,
        ],
        'search' => [
            'type' => 'text',
            'index' => true,
        ],
        'page' => [
            'type' => 'object',
            'dynamic' => false
        ]
    ];
    const PAGE_BOOL_ATTRIBUTES = ['is_active'];

    const BLOCK_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ],
        'identifier' => [
            'type' => 'keyword',
            'index' => true,
        ],
        'is_active' => [
            'type' => 'boolean',
            'index' => true,
        ],
        'block' => [
            'type' => 'object',
            'dynamic' => false
        ]
    ];
    const BLOCK_BOOL_ATTRIBUTES = ['is_active'];
    const CACHE_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ],
        'products' => [
            'type' => 'object',
            'dynamic' => false
        ]
    ];
    const CATEGORY_CACHE_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ]
    ];

    const SETTINGS_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ],
        'value' => [
            'type' => 'object',
            'dynamic' => false
        ]
    ];

    const CLEAR_STRUC = [
        'id' => [
            'type' => 'keyword',
            'index' => true
        ],
        'scope' => [
            'type' => 'keyword',
            'index' => false
        ],
        'type' => [
            'type' => 'keyword',
            'index' => true
        ]
    ];

    const EVENT_PRODUCT_UPDATE_AFTER = "wyvr_product_update_after";
}
