<?php

/**
 * @author wyvr
 * @copyright Copyright (c) 2022 Vendor (https://wyvr.dev/)
 */

namespace Wyvr\Core\Logger;

class Logger extends \Monolog\Logger
{
    public function measure($triggerName, callable $callback): void
    {
        if (is_null($triggerName)) {
            $this->error('missing triggerName in logger measure');
            return;
        }
        if (is_null($callback)) {
            $this->error('missing callback in logger measure');
            return;
        }
        $time_start = microtime(true);
        $this->notice($triggerName . ' started');

        try {
            $callback($triggerName);
        } catch (\Exception $exception) {
            $this->error('error in callback for ' . $triggerName . ' ' . $exception->getMessage());
        }

        $execution_time = ceil((microtime(true) - $time_start) * 10) / 10;
        $this->notice($triggerName . ' took ' . $execution_time . 's');
    }
}
