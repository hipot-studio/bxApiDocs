<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Entity;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\DateTime;

final class AuditSettingsEventFactory
{
	public const SOURCE = AbstractAuditEvent::SOURCE_AUDIT_SETTINGS;
	public const EVENT_ENABLED = 'disk.audit.enabled';
	public const EVENT_DISABLED = 'disk.audit.disabled';
	public const EVENT_RETENTION_CHANGED = 'disk.audit.retention.changed';
	public const OPERATION_ID_PREFIX = 'audit-settings';
	public const TARGET_ID = 'audit_settings';
	public const TARGET_NAME = 'Audit settings';
	public const SUBJECT_TYPE = 'audit_setting';

	/**
	 * @return string[]
	 */
	public static function eventCodes(): array
	{
		return [
			self::EVENT_ENABLED,
			self::EVENT_DISABLED,
			self::EVENT_RETENTION_CHANGED,
		];
	}

	/**
	 * @return string[]
	 */
	public static function allowedDetailKeys(): array
	{
		return [
			'oldEnabled',
			'newEnabled',
		];
	}

	public static function retentionDetailKeys(): array
	{
		return ['oldRetentionPeriod', 'newRetentionPeriod'];
	}

	public function createForEnabledChange(
		bool $oldEnabled,
		bool $newEnabled,
		CurrentUser $currentUser,
	): Entity\Audit\AuditMessage
	{
		return $this->create(
			eventCode: $newEnabled ? self::EVENT_ENABLED : self::EVENT_DISABLED,
			oldEnabled: $oldEnabled,
			newEnabled: $newEnabled,
			currentUser: $currentUser,
		);
	}

	public function createForRetentionChange(
		string $oldRetentionPeriod,
		string $newRetentionPeriod,
		CurrentUser $currentUser,
	): Entity\Audit\AuditMessage
	{
		return $this->create(
			eventCode: self::EVENT_RETENTION_CHANGED,
			oldEnabled: false,
			newEnabled: false,
			currentUser: $currentUser,
			details: [
				'oldRetentionPeriod' => $oldRetentionPeriod,
				'newRetentionPeriod' => $newRetentionPeriod,
			],
		);
	}

	private function create(
		string $eventCode,
		bool $oldEnabled,
		bool $newEnabled,
		CurrentUser $currentUser,
		?array $details = null,
	): Entity\Audit\AuditMessage
	{
		$target = new Entity\Audit\AuditTarget(
			type: Entity\Audit\AuditTarget::TYPE_AUDIT_SETTINGS,
			id: self::TARGET_ID,
			name: self::TARGET_NAME,
			nameSearch: mb_strtolower(self::TARGET_NAME),
		);
		$subject = new Entity\Audit\AuditSubject(
			type: self::SUBJECT_TYPE,
			id: $eventCode,
			name: $eventCode,
		);
		$operationId = $this->buildOperationId(
			eventCode: $eventCode,
			oldEnabled: $oldEnabled,
			newEnabled: $newEnabled,
		);
		$event = new Entity\Audit\AuditEvent(
			source: self::SOURCE,
			operationId: $operationId,
			eventCode: $eventCode,
			occurredAt: new DateTime(),
		);
		$idempotencyKey = (new AuditIdempotencyKeyBuilder())->build(
			source: $event->source,
			operationId: $event->operationId,
			eventCode: $event->eventCode,
			targetType: $target->type,
			targetId: $target->id,
			subjectType: $subject->type,
			subjectId: $subject->id,
		);

		return new Entity\Audit\AuditMessage(
			idempotencyKey: $idempotencyKey,
			event: $event,
			initiator: $this->createInitiator($currentUser),
			channel: Entity\Audit\AuditChannel::WEB,
			context: new Entity\Audit\AuditContext(type: Entity\Audit\AuditContext::TYPE_DISK),
			target: $target,
			subject: $subject,
			details: $details ?? [
				'oldEnabled' => $oldEnabled ? 'Y' : 'N',
				'newEnabled' => $newEnabled ? 'Y' : 'N',
			],
		);
	}

	private function createInitiator(CurrentUser $currentUser): Entity\Audit\AuditInitiator
	{
		$userId = (string)((int)$currentUser->getId());
		$name = trim((string)$currentUser->getFormattedName());

		return new Entity\Audit\AuditInitiator(
			type: Entity\Audit\AuditInitiator::TYPE_EMPLOYEE,
			id: $userId !== '0' ? $userId : 'system',
			name: $name !== '' ? $name : 'Administrator',
		);
	}

	private function buildOperationId(
		string $eventCode,
		bool $oldEnabled,
		bool $newEnabled,
	): string
	{
		return implode(':', [
			self::OPERATION_ID_PREFIX,
			$eventCode,
			$oldEnabled ? 'Y' : 'N',
			$newEnabled ? 'Y' : 'N',
			str_replace(' ', '', (string)microtime()),
		]);
	}
}
