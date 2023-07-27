<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 Vendor (https://wyvr.dev/)
 */

namespace Wyvr\Core\Logger;

class Logger extends \Monolog\Logger
{
    public function measure($triggerName, ?array $context = [], callable $callback = null): void
    {
        if (empty($context)) {
            $context = ['measure'];
        }
        if (is_null($triggerName)) {
            $this->error('missing triggerName', $context);
            return;
        }
        if (is_null($callback)) {
            $this->error('missing callback', $context);
            return;
        }
        if (!is_callable($callback)) {
            $this->error('callback not callable', $context);
            return;
        }
        $time_start = microtime(true);
        $this->info(__('%1 started', $triggerName), $context);

        try {
            $callback($triggerName);
        } catch (\Exception $exception) {
            $this->error(__('error in callback for %1, %2', $triggerName, $exception->getMessage()), $context);
        }

        $execution_time = ceil((microtime(true) - $time_start) * 10) / 10;
        $this->notice(__('%1 took %2s', $triggerName, $execution_time), $context);
    }
}
