<?php
/**
 * @author wyvr
 * @copyright Copyright (c) 2022 wyvr (https://wyvr.dev/)
 */

namespace Wyvr\Core\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverInterface;
use Wyvr\Core\Api\Constants;

class Handler extends \Magento\Framework\Logger\Handler\Base
{
    protected $loggerType;

    private $enabled;

    public function __construct(
        protected ScopeConfigInterface $scopeConfig,
        DriverInterface      $filesystem,
        protected DirectoryList $directoryList,
        string               $filePath = null,
        string               $fileName = null
    )
    {
        $root = $directoryList->getRoot();
        if (is_null($filePath)) {
            $filePath = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
        } else {
            $filePath = $root . DIRECTORY_SEPARATOR . ltrim(rtrim($filePath, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        $this->loggerType = $this->isEnabled() ? $this->getLogLevel() : \Monolog\Logger::EMERGENCY;
        parent::__construct($filesystem, $filePath, $fileName);
    }

    protected function isEnabled()
    {
        if (!is_null($this->enabled)) {
            return $this->enabled;
        }
        $this->enabled = $this->scopeConfig->isSetFlag(Constants::LOGGING_ENABLED);
        return $this->enabled;
    }

    protected function getLogLevel()
    {
        if ($this->loggerType !== null) {
            return $this->loggerType;
        }
        return intval($this->scopeConfig->getValue(Constants::LOGGING_LEVEL));
    }
}
