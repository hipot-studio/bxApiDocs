<?php

namespace Bitrix\Calendar\Internals;

use Bitrix\Main\Application;
use Bitrix\Main\Data\ManagedCache;

class mutex
{
    private string $name;
    private ManagedCache $cache;

    public function __construct(string $name)
    {
        $this->cache = Application::getInstance()->getManagedCache();
        $this->name = 'calendar_sync_'.$name.'_mutex';
    }

    public function __destruct()
    {
        // $this->unlock();
    }

    /**
     * @param int $lockTime Delta from now in seconds
     */
    public function lock(int $lockTime = 3_600): bool
    {
        $currentTime = time();
        if ($this->cache->read(1_800, $this->name)) {
            $value = $this->cache->get($this->name);
        }

        if (!empty($value) && $value > $currentTime) {
            return false;
        }

        $this->cache->setImmediate($this->name, $currentTime + $lockTime);

        return true;
    }

    public function unlock(): bool
    {
        $this->cache->setImmediate($this->name, time());
        $this->cache->clean($this->name);

        return true;
    }
}
