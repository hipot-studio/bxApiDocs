<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Event\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Dto\Audit\AuditObjectSnapshot;
use Bitrix\Disk\Internal\Dto\Audit\AuditSubject;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Internal\Service\Audit\AuditEventCodeRegistry;
use Bitrix\Disk\Internal\Service\Audit\AuditRightsDetails;
use Bitrix\Disk\Public\Event\AbstractEvent;
use Bitrix\Main\Type\DateTime;
use InvalidArgumentException;

abstract class AbstractAuditEvent extends AbstractEvent
{
	public const EVENT_NAME = 'onAuditDomainEvent';

	public const SOURCE_OBJECT_LIFECYCLE = 'object_lifecycle';
	public const SOURCE_RIGHTS = 'rights';
	public const SOURCE_PUBLIC_LINK = 'public_link';
	public const SOURCE_UNIFIED_LINK = 'unified_link';
	public const SOURCE_TRASHCAN = 'trashcan';
	public const SOURCE_AUDIT_SETTINGS = 'audit_settings';
	public const SOURCE_AUDIT_QUERY = 'audit_query';

	public readonly DateTime $occurredAt;

	public function __construct(
		public readonly AuditEventCode $eventCode,
		public readonly string $operationId,
		public readonly string $source,
		public readonly AuditActionContext $actionContext,
		public readonly string $targetType,
		public readonly string $targetId,
		public readonly ?BaseObject $targetObject = null,
		public readonly ?AuditSubject $subject = null,
		public readonly array $details = [],
		?DateTime $occurredAt = null,
		public readonly ?AuditObjectSnapshot $objectSnapshot = null,
	)
	{
		$this->assertNotEmpty($operationId, 'operationId');
		$this->assertKnownSource($source);
		$this->assertKnownTargetType($targetType);
		$this->assertNotEmpty($targetId, 'targetId');
		AuditRightsDetails::validate($eventCode->value, $details);

		$this->occurredAt = $occurredAt !== null ? clone $occurredAt : new DateTime();

		parent::__construct(
			event: self::EVENT_NAME,
			parameters: [
				'auditEvent' => $this,
			],
		);
	}

	public function toFactoryInput(): array
	{
		return [
			'eventCode' => $this->eventCode,
			'operationId' => $this->operationId,
			'source' => $this->source,
			'occurredAt' => clone $this->occurredAt,
			'actionContext' => $this->actionContext->jsonSerialize(),
			'target' => [
				'type' => $this->targetType,
				'id' => $this->targetId,
				'object' => $this->targetObject,
			],
			'subject' => $this->subject?->jsonSerialize(),
			'details' => $this->details,
		];
	}

	/**
	 * @return string[]
	 */
	public static function getKnownSources(): array
	{
		return [
			self::SOURCE_OBJECT_LIFECYCLE,
			self::SOURCE_RIGHTS,
			self::SOURCE_PUBLIC_LINK,
			self::SOURCE_UNIFIED_LINK,
			self::SOURCE_TRASHCAN,
			self::SOURCE_AUDIT_SETTINGS,
			self::SOURCE_AUDIT_QUERY,
		];
	}

	private function assertKnownSource(string $source): void
	{
		if (!in_array($source, self::getKnownSources(), true))
		{
			throw new InvalidArgumentException('Unknown disk audit event source.');
		}
	}

	private function assertKnownTargetType(string $targetType): void
	{
		if (!in_array(
			$targetType,
			[
				AuditEventCodeRegistry::TARGET_FILE,
				AuditEventCodeRegistry::TARGET_FOLDER,
				AuditEventCodeRegistry::TARGET_RIGHTS,
				AuditEventCodeRegistry::TARGET_PUBLIC_LINK,
				AuditEventCodeRegistry::TARGET_UNIFIED_LINK,
				AuditEventCodeRegistry::TARGET_TRASHCAN,
			],
			true,
		))
		{
			throw new InvalidArgumentException('Unknown disk audit target type.');
		}
	}

	private function assertNotEmpty(string $value, string $field): void
	{
		if ($value === '')
		{
			throw new InvalidArgumentException("Disk audit event field {$field} is required.");
		}
	}
}
