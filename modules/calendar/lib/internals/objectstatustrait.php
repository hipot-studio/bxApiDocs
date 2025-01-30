<?php

namespace Bitrix\Calendar\Internals;

trait objectstatustrait
{
    /** @var ObjectStatus */
    protected $objectStatus;

    public function getStatus(): ObjectStatus
    {
        if (!$this->objectStatus) {
            $this->objectStatus = new ObjectStatus();
        }

        return $this->objectStatus;
    }
}
