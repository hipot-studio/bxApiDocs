<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Entity\Audit as Entity;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use InvalidArgumentException;

final class AuditEventFactory
{
	public function __construct(
		private readonly ?AuditSnapshotBuilder $snapshotBuilder = null,
		private readonly ?AuditIdempotencyKeyBuilder $idempotencyKeyBuilder = null,
		private readonly ?AuditSecretDenylist $secretDenylist = null,
	)
	{
	}

	public function create(AbstractAuditEvent $event): Entity\AuditMessage
	{
		$metadata = AuditEventCodeRegistry::get($event->eventCode);
		$this->assertTargetMatchesMetadata($event, $metadata);

		$snapshotBuilder = $this->snapshotBuilder ?? new AuditSnapshotBuilder();
		$auditEvent = $snapshotBuilder->buildEvent($event);
		$target = $snapshotBuilder->buildTarget($event);
		$subject = $snapshotBuilder->buildSubject($event->subject);
		$idempotencyKey = $this->getIdempotencyKeyBuilder()->build(
			source: $auditEvent->source,
			operationId: $auditEvent->operationId,
			eventCode: $auditEvent->eventCode,
			targetType: $target->type,
			targetId: $target->id,
			subjectType: $subject->type,
			subjectId: $subject->id,
		);

		return new Entity\AuditMessage(
			idempotencyKey: $idempotencyKey,
			event: $auditEvent,
			initiator: $snapshotBuilder->buildInitiator($event->actionContext),
			channel: $event->actionContext->channel,
			context: $snapshotBuilder->buildContext($event->actionContext),
			target: $target,
			subject: $subject,
			details: $this->getSecretDenylist()->filterDetails(
				$event->details,
				AuditEventCodeRegistry::getAllowedDetailKeys($event->eventCode),
				$metadata['requiredDetailKeys'],
			),
		);
	}

	private function assertTargetMatchesMetadata(AbstractAuditEvent $event, array $metadata): void
	{
		if (!in_array($event->targetType, $metadata['targetTypes'], true))
		{
			throw new InvalidArgumentException('Audit event target type does not match registry metadata.');
		}

		$subjectType = $event->subject?->type;
		if ($metadata['subjectType'] !== null && $subjectType !== $metadata['subjectType'])
		{
			throw new InvalidArgumentException('Audit event subject type does not match registry metadata.');
		}
	}

	private function getIdempotencyKeyBuilder(): AuditIdempotencyKeyBuilder
	{
		return $this->idempotencyKeyBuilder ?? new AuditIdempotencyKeyBuilder();
	}

	private function getSecretDenylist(): AuditSecretDenylist
	{
		return $this->secretDenylist ?? new AuditSecretDenylist();
	}
}
