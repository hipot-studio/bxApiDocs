<?php

namespace Bitrix\Crm\RepeatSale\DataCollector;

use Bitrix\Crm\ItemIdentifier;
use Psr\Log\LoggerInterface;

final class AiScreeningDataCollectorConfig
{
	public function __construct(
		private readonly ItemIdentifier $targetItemIdentifier,
		private readonly array $clientIdentifiers,
		private readonly int $userId,
		private readonly ?LoggerInterface $logger = null,
	)
	{

	}

	public function getTargetItemIdentifier(): ItemIdentifier
	{
		return $this->targetItemIdentifier;
	}

	public function getClientIdentifiers(): array
	{
		return $this->clientIdentifiers;
	}

	public function getLogger(): ?LoggerInterface
	{
		return $this->logger;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}
}
