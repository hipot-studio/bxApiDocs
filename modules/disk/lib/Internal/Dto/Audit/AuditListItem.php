<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

use Bitrix\Main\Type\DateTime;
use JsonSerializable;

final class AuditListItem implements JsonSerializable
{
	public readonly DateTime $occurredAt;

	public function __construct(
		public readonly int $id,
		public readonly string $eventCode,
		DateTime $occurredAt,
		public readonly string $initiatorType,
		public readonly string $initiatorId,
		public readonly string $initiatorName,
		public readonly string $channel,
		public readonly string $contextType,
		public readonly ?string $contextEntityType,
		public readonly ?string $contextEntityId,
		public readonly ?string $contextEntityName,
		public readonly string $targetType,
		public readonly string $targetId,
		public readonly string $targetName,
		public readonly string $result,
		public readonly ?int $storageId,
		public readonly ?string $storageName,
	)
	{
		$this->occurredAt = clone $occurredAt;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'eventCode' => $this->eventCode,
			'occurredAt' => $this->occurredAt->format(\DateTimeInterface::ATOM),
			'initiator' => [
				'type' => $this->initiatorType,
				'id' => $this->initiatorId,
				'name' => $this->initiatorName,
			],
			'channel' => $this->channel,
			'context' => [
				'type' => $this->contextType,
				'entityType' => $this->contextEntityType,
				'entityId' => $this->contextEntityId,
				'entityName' => $this->contextEntityName,
			],
			'target' => [
				'type' => $this->targetType,
				'id' => $this->targetId,
				'name' => $this->targetName,
			],
			'result' => $this->result,
			'storage' => [
				'id' => $this->storageId,
				'name' => $this->storageName,
			],
		];
	}
}
