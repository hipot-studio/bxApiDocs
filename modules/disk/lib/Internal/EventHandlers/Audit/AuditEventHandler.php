<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\EventHandlers\Audit;

use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Internal\Service\Audit\AuditAvailabilityService;
use Bitrix\Disk\Internal\Service\Audit\AuditCaptureService;
use Bitrix\Disk\Internal\Service\Audit\AuditEventCodeRegistry;
use Bitrix\Disk\Internal\Service\Audit\AuditEventFactory;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use RuntimeException;
use Throwable;

final class AuditEventHandler
{
	public const ERROR_UNSUPPORTED_EVENT = 'DISK_AUDIT_EVENT_UNSUPPORTED';
	public const ERROR_FACTORY_FAILED = 'DISK_AUDIT_EVENT_FACTORY_FAILED';

	public function __construct(
		private readonly ?AuditCaptureService $captureService = null,
		private readonly ?AuditEventFactory $auditEventFactory = null,
		private readonly mixed $messageFactory = null,
		private readonly ?AuditAvailabilityService $availabilityService = null,
	)
	{
	}

	public static function handle(Event $event): Result
	{
		return (new self())->handleEvent($event);
	}

	public function handleEvent(Event $event): Result
	{
		try
		{
			$auditEvent = $this->extractAuditEvent($event);
			if ($auditEvent === null)
			{
				$this->writeToLog(new RuntimeException('Only disk typed audit events can be captured.'));

				return new Result();
			}

			if (!$this->getAvailabilityService()->canCollect())
			{
				return new Result();
			}

			$factoryResult = $this->createMessage($auditEvent);
			if (!$factoryResult->isSuccess())
			{
				$this->writeResultToLog($factoryResult);

				return new Result();
			}

			$message = $factoryResult->getData()['message'];
			Application::getInstance()->addBackgroundJob(function () use ($auditEvent, $message): void {
				try
				{
					if (!$this->isPersisted($auditEvent))
					{
						return;
					}

					$result = $this->getCaptureService()->capture(
						$message,
						AuditEventCodeRegistry::getAllowedDetailKeys($auditEvent->eventCode),
					);
					if (!$result->isSuccess())
					{
						$this->writeResultToLog($result);
					}
				}
				catch (Throwable $exception)
				{
					$this->writeToLog($exception);
				}
			});
		}
		catch (Throwable $exception)
		{
			$this->writeToLog($exception);
		}

		return new Result();
	}

	public function capture(AbstractAuditEvent $event): Result
	{
		try
		{
			if (!$this->getAvailabilityService()->canCollect())
			{
				return new Result();
			}

			$factoryResult = $this->createMessage($event);
			if (!$factoryResult->isSuccess())
			{
				$this->writeResultToLog($factoryResult);

				return new Result();
			}

			$result = $this->getCaptureService()->capture(
				$factoryResult->getData()['message'],
				AuditEventCodeRegistry::getAllowedDetailKeys($event->eventCode),
			);
			if (!$result->isSuccess())
			{
				$this->writeResultToLog($result);
			}
		}
		catch (Throwable $exception)
		{
			$this->writeToLog($exception);
		}

		return new Result();
	}

	private function extractAuditEvent(Event $event): ?AbstractAuditEvent
	{
		if ($event instanceof AbstractAuditEvent)
		{
			return $event;
		}

		$auditEvent = $event->getParameter('auditEvent');

		return $auditEvent instanceof AbstractAuditEvent ? $auditEvent : null;
	}

	private function createMessage(AbstractAuditEvent $event): Result
	{
		try
		{
			if (is_callable($this->messageFactory))
			{
				$message = ($this->messageFactory)($event);

				return (new Result())->setData([
					'message' => $message,
				]);
			}

			return (new Result())->setData([
				'message' => $this->getAuditEventFactory()->create($event),
			]);
		}
		catch (\Throwable $exception)
		{
			return (new Result())->addError(
				new Error($exception->getMessage(), self::ERROR_FACTORY_FAILED),
			);
		}
	}

	private function getCaptureService(): AuditCaptureService
	{
		return $this->captureService ?? ServiceLocator::getInstance()->get(AuditCaptureService::class);
	}

	private function getAuditEventFactory(): AuditEventFactory
	{
		return $this->auditEventFactory ?? new AuditEventFactory();
	}

	private function getAvailabilityService(): AuditAvailabilityService
	{
		return $this->availabilityService ?? ServiceLocator::getInstance()->get(AuditAvailabilityService::class);
	}

	private function isPersisted(AbstractAuditEvent $event): bool
	{
		if ($event->source !== AbstractAuditEvent::SOURCE_OBJECT_LIFECYCLE)
		{
			return true;
		}

		$row = ObjectTable::getByPrimary((int)$event->targetId, [
			'select' => ['NAME', 'PARENT_ID', 'STORAGE_ID', 'DELETED_TYPE', 'SIZE'],
		])->fetch();

		return match ($event->eventCode)
		{
			AuditEventCode::ObjectPermanentlyDeleted => $row === false,
			AuditEventCode::ObjectCreated => $row !== false,
			AuditEventCode::ObjectCopied => $row !== false
				&& (int)$row['PARENT_ID'] === (int)($event->details['targetParentId'] ?? 0),
			AuditEventCode::ObjectContentChanged => $row !== false
				&& (int)$row['SIZE'] === (int)($event->details['newSize'] ?? -1),
			AuditEventCode::ObjectRenamed => $row !== false
				&& (string)$row['NAME'] === (string)($event->details['newName'] ?? ''),
			AuditEventCode::ObjectMoved => $row !== false && (
				(
					(int)($event->details['oldStorageId'] ?? 0) !== (int)($event->details['newStorageId'] ?? 0)
					&& (int)$row['DELETED_TYPE'] === ObjectTable::DELETED_TYPE_ROOT
				)
				|| (
					(int)$row['PARENT_ID'] === (int)($event->details['newParentId'] ?? 0)
					&& (int)$row['STORAGE_ID'] === (int)($event->details['newStorageId'] ?? 0)
				)
			),
			AuditEventCode::ObjectMarkedDeleted => $row !== false
				&& (int)$row['DELETED_TYPE'] === (int)($event->details['deletedType'] ?? -1),
			AuditEventCode::ObjectRestored => $row !== false
				&& (int)$row['DELETED_TYPE'] === ObjectTable::DELETED_TYPE_NONE
				&& (int)$row['PARENT_ID'] === (int)($event->details['restoredParentId'] ?? 0),
			default => true,
		};
	}

	private function writeResultToLog(Result $result): void
	{
		$this->writeToLog(new RuntimeException(implode('; ', $result->getErrorMessages())));
	}

	private function writeToLog(Throwable $exception): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
		catch (Throwable)
		{
		}
	}
}
