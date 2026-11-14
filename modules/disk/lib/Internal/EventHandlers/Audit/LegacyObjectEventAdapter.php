<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\EventHandlers\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Internal\Service\Audit\AuditEventCodeRegistry;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use Bitrix\Disk\Public\Event\Audit\AuditObjectEvent;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Result;

final class LegacyObjectEventAdapter
{
	public const ERROR_INCOMPLETE_CONTEXT = 'DISK_AUDIT_LEGACY_INCOMPLETE_CONTEXT';
	public const ERROR_UNSUPPORTED_LEGACY_EVENT = 'DISK_AUDIT_LEGACY_UNSUPPORTED_EVENT';

	public function createFromEvent(Event $event): Result
	{
		$contextResult = $this->extractContext($event);
		if (!$contextResult->isSuccess())
		{
			return $contextResult;
		}

		$eventResult = $this->buildTypedEvent($event, $contextResult->getData());
		if (!$eventResult->isSuccess())
		{
			return $eventResult;
		}

		return $eventResult;
	}

	private function extractContext(Event $event): Result
	{
		$result = new Result();
		$operationId = $event->getParameter('operationId');
		$actionContext = $event->getParameter('actionContext');

		if (
			!is_string($operationId)
			|| $operationId === ''
			|| !($actionContext instanceof AuditActionContext)
		)
		{
			return $result->addError(
				new Error(
					'Legacy disk event cannot be converted to audit event without full action context.',
					self::ERROR_INCOMPLETE_CONTEXT,
				),
			);
		}

		return $result->setData([
			'operationId' => $operationId,
			'actionContext' => $actionContext,
		]);
	}

	private function buildTypedEvent(Event $event, array $context): Result
	{
		$result = new Result();

		if ($event->getEventType() !== 'onAfterRenameObject')
		{
			return $result->addError(
				new Error('Legacy disk event payload is not sufficient for audit capture.', self::ERROR_UNSUPPORTED_LEGACY_EVENT),
			);
		}

		$object = $event->getParameter('object');
		$oldName = $event->getParameter('oldName');
		$newName = $event->getParameter('newName');

		if (!($object instanceof BaseObject) || !is_string($oldName) || !is_string($newName))
		{
			return $result->addError(
				new Error('Legacy rename event payload is incomplete.', self::ERROR_INCOMPLETE_CONTEXT),
			);
		}

		return $result->setData([
			'event' => new AuditObjectEvent(
				eventCode: AuditEventCode::ObjectRenamed,
				operationId: $context['operationId'],
				source: AbstractAuditEvent::SOURCE_OBJECT_LIFECYCLE,
				actionContext: $context['actionContext'],
				targetType: $this->resolveTargetType($object),
				targetId: (string)$object->getId(),
				targetObject: $object,
				details: [
					'oldName' => $oldName,
					'newName' => $newName,
				],
			),
		]);
	}

	private function resolveTargetType(BaseObject $object): string
	{
		if ($object instanceof File)
		{
			return AuditEventCodeRegistry::TARGET_FILE;
		}

		if ($object instanceof Folder)
		{
			return AuditEventCodeRegistry::TARGET_FOLDER;
		}

		return AuditEventCodeRegistry::TARGET_FILE;
	}
}
