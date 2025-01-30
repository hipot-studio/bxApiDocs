<?php

namespace Bitrix\Calendar\Internals;

trait handlestatustrait
{
    /** @var callable[] */
    protected array $statusHandlers = [];

    /**
     * @return $this
     */
    public function addStatusHandler(callable $handler): self
    {
        $this->statusHandlers[] = $handler;

        return $this;
    }

    /**
     * @param callable[] $handlers
     *
     * @return $this
     */
    public function addStatusHandlerList(array $handlers): self
    {
        foreach ($handlers as $handler) {
            $this->statusHandlers[] = $handler;
        }

        return $this;
    }

    /**
     * @return callable[]
     */
    public function getStatusHandlerList(): array
    {
        return $this->statusHandlers;
    }

    protected function sendStatus($status)
    {
        foreach ($this->statusHandlers as $statusHandler) {
            \call_user_func($statusHandler, $status);
        }
    }
}
