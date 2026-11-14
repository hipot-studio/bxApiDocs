<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Entity\Audit\AuditContext;
use Bitrix\Disk\Internal\Entity\Audit\AuditEvent;
use Bitrix\Disk\Internal\Entity\Audit\AuditInitiator;
use Bitrix\Disk\Internal\Entity\Audit\AuditMessage;
use Bitrix\Disk\Internal\Entity\Audit\AuditSubject;
use Bitrix\Disk\Internal\Entity\Audit\AuditTarget;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class AuditPageOpenedRecorder
{
	public const EVENT_CODE = 'disk.audit.log.opened';

	private const SOURCE = 'disk.audit.query';
	private const TARGET_ID = 'audit-log';

	public function __construct(
		private readonly ?AuditCaptureService $captureService = null,
		private readonly ?AuditIdempotencyKeyBuilder $idempotencyKeyBuilder = null,
		private readonly ?AuditActionContextResolver $actionContextResolver = null,
	)
	{
	}

	public function record(string $instanceId, int $issuedAt, CurrentUser $currentUser): Result
	{
		$userId = (int)$currentUser->getId();
		$operationId = 'page-open:' . $userId . ':' . $instanceId;
		$idempotencyKey = $this->getIdempotencyKeyBuilder()->build(
			source: self::SOURCE,
			operationId: $operationId,
			eventCode: self::EVENT_CODE,
			targetType: AuditTarget::TYPE_AUDIT_LOG,
			targetId: self::TARGET_ID,
		);
		$actionContext = $this->getActionContextResolver()->resolveForWeb($userId);

		$message = new AuditMessage(
			idempotencyKey: $idempotencyKey,
			event: new AuditEvent(
				source: self::SOURCE,
				operationId: $operationId,
				eventCode: self::EVENT_CODE,
				occurredAt: DateTime::createFromTimestamp($issuedAt),
			),
			initiator: new AuditInitiator(
				type: $actionContext->initiatorType,
				id: $actionContext->initiatorId,
				name: $actionContext->initiatorName,
				ip: $actionContext->ip,
			),
			channel: $actionContext->channel,
			context: new AuditContext(
				type: $actionContext->contextType,
				entityType: $actionContext->contextEntityType,
				entityId: $actionContext->contextEntityId,
				entityName: $actionContext->contextEntityName,
			),
			target: new AuditTarget(
				type: AuditTarget::TYPE_AUDIT_LOG,
				id: self::TARGET_ID,
				name: 'Audit log',
				nameSearch: 'audit log',
			),
			subject: new AuditSubject(),
		);

		return $this->getCaptureService()->capture($message);
	}

	private function getCaptureService(): AuditCaptureService
	{
		return $this->captureService ?? ServiceLocator::getInstance()->get(AuditCaptureService::class);
	}

	private function getIdempotencyKeyBuilder(): AuditIdempotencyKeyBuilder
	{
		return $this->idempotencyKeyBuilder ?? new AuditIdempotencyKeyBuilder();
	}

	private function getActionContextResolver(): AuditActionContextResolver
	{
		return $this->actionContextResolver ?? new AuditActionContextResolver();
	}
}
