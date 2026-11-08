<?php

namespace Bitrix\Rest\V3\Event\Payload;

use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Event\Payload\Handler\HandlerInterface;

abstract class AbstractPayload implements PayloadInterface
{
	public function __construct(private readonly ?string $handlerClass = null)
	{
		if ($this->handlerClass === null)
		{
			return;
		}

		if (!class_exists($this->handlerClass))
		{
			throw new ArgumentException("Handler {$this->handlerClass} not found");
		}

		if (!is_a($this->handlerClass, HandlerInterface::class, true))
		{
			throw new ArgumentException(
				"Handler class {$this->handlerClass} must implement " . HandlerInterface::class
			);
		}
	}

	public function getHandlerClass(): ?string
	{
		return $this->handlerClass;
	}
}