<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Convert;

class ConvertHandlerStatus
{
	private array $handlerStatus;

	public function __construct()
	{
		$this->handlerStatus = $this->getDefaultHandlerStatus();
	}

	public function markExecuted(ConvertTrackedHandler $handler): void
	{
		$this->handlerStatus[$handler->value] = true;
	}

	public function isExecuted(ConvertTrackedHandler $handler): bool
	{
		return $this->handlerStatus[$handler->value] === true;
	}

	private function getDefaultHandlerStatus(): array
	{
		return array_fill_keys(
			array_map(
				static fn (ConvertTrackedHandler $handler): string => $handler->value,
				ConvertTrackedHandler::cases(),
			),
			false,
		);
	}
}
