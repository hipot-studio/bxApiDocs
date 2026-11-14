<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Dto\Audit\AuditObjectSnapshot;
use Bitrix\Disk\Internal\Dto\Audit\AuditSubject;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use Bitrix\Disk\Public\Event\Audit\AuditRightsEvent;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Main\Result;
use Throwable;

final class AuditRightsEventProducer
{
	public const SOURCE = AbstractAuditEvent::SOURCE_RIGHTS;
	public const ERROR_EVENT_HANDLER_FAILED = 'DISK_AUDIT_RIGHTS_EVENT_HANDLER_FAILED';

	public function __construct(
		private readonly ?AuditRightsDiffBuilder $diffBuilder = null,
		private readonly ?AuditRightsCascadeDetector $cascadeDetector = null,
		private readonly mixed $sender = null,
		private readonly ?AuditSnapshotBuilder $snapshotBuilder = null,
		private readonly ?AuditAvailabilityService $availabilityService = null,
	)
	{
	}

	public function publishChanges(
		BaseObject $object,
		array $oldMembersAccess,
		array $newMembersAccess,
		?int $changedBy,
		?AuditOperationContext $operationContext = null,
	): Result
	{
		return AuditCaptureService::runFailOpen(function () use (
			$object, $oldMembersAccess, $newMembersAccess, $changedBy, $operationContext,
		): Result {
			$result = new Result();
			$diffBuilder = $this->diffBuilder ?? new AuditRightsDiffBuilder();
			$cascadeDetector = $this->cascadeDetector ?? new AuditRightsCascadeDetector();
			$subjectChanges = $diffBuilder->buildSubjectChanges($oldMembersAccess, $newMembersAccess);
			$inheritanceChanges = $diffBuilder->buildInheritanceChanges($oldMembersAccess, $newMembersAccess);
			$cascadeChange = $cascadeDetector->isCascadeCommand($object)
				? $diffBuilder->buildCascadeChange(
					$subjectChanges,
					$cascadeDetector->getCascadeRootObjectId($object),
				)
				: null;

			$context = $operationContext ?? AuditOperationContextStack::current();
			$operationId = (new AuditNormalizer())->resolveOperationId($context);
			$actionContext = $context?->actionContext ?? $this->createActionContext($changedBy, $context?->channel);

			if ($cascadeChange === null)
			{
				foreach ($subjectChanges as $change)
				{
					$eventResult = $this->publishSubjectChange($object, $change, $operationId, $actionContext);
					if (!$eventResult->isSuccess())
					{
						return $result->addErrors($eventResult->getErrors());
					}
				}
			}

			foreach ($inheritanceChanges as $change)
			{
				$eventResult = $this->publishInheritanceChange($object, $change, $operationId, $actionContext);
				if (!$eventResult->isSuccess())
				{
					return $result->addErrors($eventResult->getErrors());
				}
			}

			if ($cascadeChange !== null)
			{
				$eventResult = $this->publishCascadeChange($object, $cascadeChange, $operationId, $actionContext);
				if (!$eventResult->isSuccess())
				{
					return $result->addErrors($eventResult->getErrors());
				}
			}

			return $result;
		});
	}

	public function publishDirectChanges(
		BaseObject $object,
		array $before,
		array $after,
		AuditOperationContext $context,
	): Result
	{
		return AuditCaptureService::runFailOpen(function () use ($object, $before, $after, $context): Result {
			$builder = $this->diffBuilder ?? new AuditRightsDiffBuilder();
			$changes = $builder->buildDirectSubjectChanges($before, $after);
			if ($changes === [])
			{
				return new Result();
			}
			$snapshot = $this->getAvailabilityService()->canCollect()
				? ($this->snapshotBuilder ?? new AuditSnapshotBuilder())->buildObjectSnapshot($object)
				: null
			;
			$actionContext = $context->actionContext ?? $this->createActionContext(null, $context->channel);
			$detector = $this->cascadeDetector ?? new AuditRightsCascadeDetector();
			if ($detector->isCascadeCommand($object, $context))
			{
				$cascade = $builder->buildCascadeChange($changes, $detector->getCascadeRootObjectId($object));

				return $cascade === null ? new Result()
					: $this->publishCascadeChange($object, $cascade, $context->operationId, $actionContext, $snapshot);
			}
			foreach ($changes as $change)
			{
				$result = $this->publishSubjectChange($object, $change, $context->operationId, $actionContext, $snapshot);
				if (!$result->isSuccess())
				{
					return $result;
				}
			}

			return new Result();
		});
	}

	public function publishEvents(array $events): Result
	{
		return AuditCaptureService::runFailOpen(function () use ($events): Result {
			if ($events === [])
			{
				return new Result();
			}
			$canCollect = $this->getAvailabilityService()->canCollect();
			$snapshots = [];
			foreach ($events as $event)
			{
				if ($canCollect && $event->objectSnapshot === null && $event->targetObject !== null)
				{
					$key = serialize([$event->operationId, $event->targetType, $event->targetId]);
					$snapshots[$key] ??= ($this->snapshotBuilder ?? new AuditSnapshotBuilder())
						->buildObjectSnapshot($event->targetObject)
					;
					$event = new AuditRightsEvent(
						eventCode: $event->eventCode,
						operationId: $event->operationId,
						source: $event->source,
						actionContext: $event->actionContext,
						targetType: $event->targetType,
						targetId: $event->targetId,
						targetObject: $event->targetObject,
						subject: $event->subject,
						details: $event->details,
						occurredAt: $event->occurredAt,
						objectSnapshot: $snapshots[$key],
					);
				}
				$result = $this->send($event);
				if (!$result->isSuccess())
				{
					return $result;
				}
			}

			return new Result();
		});
	}

	private function getAvailabilityService(): AuditAvailabilityService
	{
		return $this->availabilityService ?? ServiceLocator::getInstance()->get(AuditAvailabilityService::class);
	}

	private function publishCascadeChange(
		BaseObject $object,
		array $change,
		string $operationId,
		AuditActionContext $actionContext,
		?AuditObjectSnapshot $objectSnapshot = null,
	): Result
	{
		return $this->sendEvent(
			object: $object,
			eventCode: $change['eventCode'],
			operationId: $operationId,
			actionContext: $actionContext,
			subject: null,
			objectSnapshot: $objectSnapshot,
			details: [
				'cascadeRootObjectId' => $change['cascadeRootObjectId'],
				'addedCount' => $change['addedCount'],
				'changedCount' => $change['changedCount'],
				'removedCount' => $change['removedCount'],
			],
		);
	}

	private function publishSubjectChange(
		BaseObject $object,
		array $change,
		string $operationId,
		AuditActionContext $actionContext,
		?AuditObjectSnapshot $objectSnapshot = null,
	): Result
	{
		$accessCode = (string)$change['accessCode'];
		$details = ['accessCode' => $accessCode];

		foreach ([
			'oldTaskName', 'newTaskName', 'cascadeRootObjectId', 'oldDomain', 'newDomain',
			'oldNegative', 'newNegative', 'oldRights', 'newRights',
		] as $detailKey)
		{
			if (isset($change[$detailKey]))
			{
				$details[$detailKey] = $change[$detailKey];
			}
		}

		return $this->sendEvent(
			object: $object,
			eventCode: $change['eventCode'],
			operationId: $operationId,
			actionContext: $actionContext,
			subject: new AuditSubject(AuditSubject::TYPE_ACCESS_SUBJECT, $accessCode),
			details: $details,
			objectSnapshot: $objectSnapshot,
		);
	}

	private function publishInheritanceChange(
		BaseObject $object,
		array $change,
		string $operationId,
		AuditActionContext $actionContext,
	): Result
	{
		return $this->sendEvent(
			object: $object,
			eventCode: $change['eventCode'],
			operationId: $operationId,
			actionContext: $actionContext,
			subject: new AuditSubject(AuditSubject::TYPE_INHERITANCE, (string)$object->getId()),
			details: [
				'oldMode' => $change['oldMode'],
				'newMode' => $change['newMode'],
			],
		);
	}

	private function sendEvent(
		BaseObject $object,
		AuditEventCode $eventCode,
		string $operationId,
		AuditActionContext $actionContext,
		?AuditSubject $subject,
		array $details,
		?AuditObjectSnapshot $objectSnapshot = null,
	): Result
	{
		$event = new AuditRightsEvent(
			eventCode: $eventCode,
			operationId: $operationId,
			source: self::SOURCE,
			actionContext: $actionContext,
			targetType: AuditEventCodeRegistry::TARGET_RIGHTS,
			targetId: (string)$object->getId(),
			targetObject: $object,
			subject: $subject,
			details: $details,
			objectSnapshot: $objectSnapshot,
		);

		return $this->send($event);
	}

	private function createActionContext(?int $userId, ?string $channel = null): AuditActionContext
	{
		return (new AuditActionContextResolver())->resolve(
			userId: $userId,
			channel: $channel,
		);
	}

	private function send(AuditRightsEvent $event): Result
	{
		$result = new Result();

		if (is_callable($this->sender))
		{
			$senderResult = ($this->sender)($event);
			if ($senderResult instanceof Result)
			{
				return $senderResult;
			}

			return $result;
		}

		try
		{
			$event->send();
		}
		catch (Throwable $exception)
		{
			return $result->addError(
				new Error($exception->getMessage(), self::ERROR_EVENT_HANDLER_FAILED),
			);
		}

		foreach ($event->getResults() as $eventResult)
		{
			$handlerResult = $eventResult->getParameters();
			if ($handlerResult instanceof Result && !$handlerResult->isSuccess())
			{
				return $result->addErrors($handlerResult->getErrors());
			}

			if ($eventResult->getType() === EventResult::ERROR)
			{
				return $result->addError(
					new Error('Disk audit event handler returned an error.', self::ERROR_EVENT_HANDLER_FAILED),
				);
			}
		}

		return $result;
	}
}
