<?php
/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LogLevel implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value'=> \Monolog\Logger::DEBUG, 'label'=>'Debug'],
            ['value'=> \Monolog\Logger::INFO, 'label'=>'Info'],
            ['value'=> \Monolog\Logger::NOTICE, 'label'=>'Notice'],
            ['value'=> \Monolog\Logger::WARNING, 'label'=>'Warning'],
            ['value'=> \Monolog\Logger::ERROR, 'label'=>'Error'],
            ['value'=> \Monolog\Logger::CRITICAL, 'label'=>'Critical'],
            ['value'=> \Monolog\Logger::ALERT, 'label'=>'Alert'],
            ['value'=> \Monolog\Logger::EMERGENCY, 'label'=>'Emergency'],
        ];
    }
}
