<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Convert;

class ConvertProgress
{
	public function __construct(
		private readonly int $collabId,
		private ?ConvertHandlerStatus $handlerStatus = null,
		private ?ConvertStatus $status = null,
	)
	{
		$this->handlerStatus ??= new ConvertHandlerStatus();
	}

	public function getCollabId(): int
	{
		return $this->collabId;
	}

	public function setStatus(ConvertStatus $status): void
	{
		$this->status = $status;
	}

	public function getStatus(): ?ConvertStatus
	{
		return $this->status;
	}

	public function markHandlerExecuted(ConvertTrackedHandler $handler): void
	{
		$this->handlerStatus->markExecuted($handler);
	}

	public function isHandlerExecuted(ConvertTrackedHandler $handler): bool
	{
		return $this->handlerStatus->isExecuted($handler);
	}
}
