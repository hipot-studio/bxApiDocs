<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Dto\Audit\AuditObjectSnapshot;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use Bitrix\Disk\Public\Event\Audit\AuditObjectEvent;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Main\Result;
use Throwable;

final class AuditObjectEventProducer
{
	public const SOURCE = AbstractAuditEvent::SOURCE_OBJECT_LIFECYCLE;
	public const ERROR_UNSUPPORTED_OBJECT_TYPE = 'DISK_AUDIT_OBJECT_UNSUPPORTED_TYPE';
	public const ERROR_EVENT_HANDLER_FAILED = 'DISK_AUDIT_OBJECT_EVENT_HANDLER_FAILED';

	public function __construct(
		private readonly mixed $sender = null,
	)
	{
	}

	public function publishCreated(BaseObject $object, ?int $createdBy = null): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendObjectEvent(
			eventCode: AuditEventCode::ObjectCreated,
			object: $object,
			userId: $createdBy ?? (int)$object->getCreatedBy(),
			details: [
				'objectType' => $this->resolveTargetType($object),
			],
		));
	}

	public function publishContentChanged(File $file, ?int $updatedBy, int $oldSize, int $newSize): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendObjectEvent(
			eventCode: AuditEventCode::ObjectContentChanged,
			object: $file,
			userId: $updatedBy,
			details: [
				'oldSize' => $oldSize,
				'newSize' => $newSize,
			],
		));
	}

	public function publishRenamed(BaseObject $object, string $oldName, string $newName): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendObjectEvent(
			eventCode: AuditEventCode::ObjectRenamed,
			object: $object,
			userId: $this->getCurrentUserId(),
			details: [
				'oldName' => $oldName,
				'newName' => $newName,
			],
		));
	}

	public function publishMoved(
		BaseObject $object,
		?int $movedBy,
		int $oldParentId,
		int $newParentId,
		int $oldStorageId,
		int $newStorageId,
	): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendObjectEvent(
			eventCode: AuditEventCode::ObjectMoved,
			object: $object,
			userId: $movedBy,
			details: [
				'oldParentId' => $oldParentId,
				'newParentId' => $newParentId,
				'oldStorageId' => $oldStorageId,
				'newStorageId' => $newStorageId,
			],
		));
	}

	public function publishCopied(BaseObject $sourceObject, BaseObject $copiedObject, ?int $copiedBy): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendObjectEvent(
			eventCode: AuditEventCode::ObjectCopied,
			object: $copiedObject,
			userId: $copiedBy,
			details: [
				'sourceObjectId' => (int)$sourceObject->getId(),
				'targetParentId' => (int)$copiedObject->getParentId(),
			],
		));
	}

	public function publishMarkedDeleted(BaseObject $object, ?int $deletedBy, int $deletedType): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendObjectEvent(
			eventCode: AuditEventCode::ObjectMarkedDeleted,
			object: $object,
			userId: $deletedBy,
			details: [
				'deletedType' => $deletedType,
			],
		));
	}

	public function publishRestored(BaseObject $object, ?int $restoredBy): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendObjectEvent(
			eventCode: AuditEventCode::ObjectRestored,
			object: $object,
			userId: $restoredBy,
			details: [
				'restoredParentId' => (int)$object->getParentId(),
			],
		));
	}

	public function publishPermanentlyDeleted(
		BaseObject $object,
		?int $deletedBy,
		string $deleteScope = 'object',
		?AuditObjectSnapshot $objectSnapshot = null,
	): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendObjectEvent(
			eventCode: AuditEventCode::ObjectPermanentlyDeleted,
			object: $object,
			userId: $deletedBy,
			details: [
				'deleteScope' => $deleteScope,
			],
			objectSnapshot: $objectSnapshot,
		));
	}

	private function sendObjectEvent(
		AuditEventCode $eventCode,
		BaseObject $object,
		?int $userId,
		array $details,
		?AuditObjectSnapshot $objectSnapshot = null,
	): Result
	{
		$result = new Result();
		$normalizer = new AuditNormalizer();
		$context = AuditOperationContextStack::current();
		if (!$normalizer->shouldEmit($eventCode, (string)$object->getId(), $details, $context))
		{
			return $result;
		}

		$targetType = $this->resolveTargetType($object);
		if ($targetType === null)
		{
			return $result->addError(
				new Error('Disk audit object event target type is not supported.', self::ERROR_UNSUPPORTED_OBJECT_TYPE),
			);
		}

		$event = new AuditObjectEvent(
			eventCode: $eventCode,
			operationId: $normalizer->resolveOperationId($context),
			source: self::SOURCE,
			actionContext: $context?->actionContext ?? $this->createActionContext($userId, $context?->channel),
			targetType: $targetType,
			targetId: (string)$object->getId(),
			targetObject: $object,
			details: $details,
			objectSnapshot: $objectSnapshot,
		);

		return $this->send($event);
	}

	private function resolveTargetType(BaseObject $object): ?string
	{
		if ($object instanceof File || (int)$object->getType() === ObjectTable::TYPE_FILE)
		{
			return AuditEventCodeRegistry::TARGET_FILE;
		}

		if ($object instanceof Folder || (int)$object->getType() === ObjectTable::TYPE_FOLDER)
		{
			return AuditEventCodeRegistry::TARGET_FOLDER;
		}

		return null;
	}

	private function createActionContext(?int $userId, ?string $channel = null): AuditActionContext
	{
		return (new AuditActionContextResolver())->resolve(
			userId: $userId,
			channel: $channel,
		);
	}

	private function getCurrentUserId(): ?int
	{
		global $USER;

		if ($USER instanceof \CUser && $USER->isAuthorized())
		{
			return (int)$USER->getId();
		}

		return null;
	}

	private function send(AuditObjectEvent $event): Result
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
