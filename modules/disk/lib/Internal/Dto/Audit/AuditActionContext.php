<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

use Bitrix\Disk\Internal\Entity\Audit\AuditChannel;
use Bitrix\Disk\Internal\Entity\Audit\AuditContext;
use Bitrix\Disk\Internal\Entity\Audit\AuditInitiator;
use InvalidArgumentException;
use JsonSerializable;

final class AuditActionContext implements JsonSerializable
{
	public function __construct(
		public readonly string $initiatorType,
		public readonly string $initiatorId,
		public readonly string $initiatorName,
		public readonly string $channel,
		public readonly string $contextType = AuditContext::TYPE_DISK,
		public readonly ?string $ip = null,
		public readonly ?string $contextEntityType = null,
		public readonly ?string $contextEntityId = null,
		public readonly ?string $contextEntityName = null,
	)
	{
		AuditChannel::assertKnown($channel);
		$this->assertKnownInitiatorType($initiatorType);
		$this->assertNotEmpty($initiatorId, 'initiatorId');
		$this->assertNotEmpty($initiatorName, 'initiatorName');
		$this->assertNotEmpty($contextType, 'contextType');
	}

	public function jsonSerialize(): array
	{
		return [
			'initiatorType' => $this->initiatorType,
			'initiatorId' => $this->initiatorId,
			'initiatorName' => $this->initiatorName,
			'channel' => $this->channel,
			'contextType' => $this->contextType,
			'ip' => $this->ip,
			'contextEntityType' => $this->contextEntityType,
			'contextEntityId' => $this->contextEntityId,
			'contextEntityName' => $this->contextEntityName,
		];
	}

	private function assertKnownInitiatorType(string $initiatorType): void
	{
		if (!in_array(
			$initiatorType,
			[
				AuditInitiator::TYPE_EMPLOYEE,
				AuditInitiator::TYPE_EXTERNAL_USER,
				AuditInitiator::TYPE_APPLICATION,
				AuditInitiator::TYPE_SYSTEM,
			],
			true,
		))
		{
			throw new InvalidArgumentException('Unknown disk audit initiator type.');
		}
	}

	private function assertNotEmpty(string $value, string $field): void
	{
		if ($value === '')
		{
			throw new InvalidArgumentException("Disk audit action context field {$field} is required.");
		}
	}
}
