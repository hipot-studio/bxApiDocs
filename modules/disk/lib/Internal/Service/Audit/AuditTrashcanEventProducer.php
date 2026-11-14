<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Dto\Audit\AuditSubject;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use Bitrix\Disk\Public\Event\Audit\AuditObjectEvent;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Main\Result;
use Throwable;

final class AuditTrashcanEventProducer
{
	public const SOURCE = AbstractAuditEvent::SOURCE_TRASHCAN;
	public const ERROR_EVENT_HANDLER_FAILED = 'DISK_AUDIT_TRASHCAN_EVENT_HANDLER_FAILED';

	public function __construct(
		private readonly mixed $sender = null,
	)
	{
	}

	public function publishEmptied(int $storageId, ?int $emptiedBy): Result
	{
		return AuditCaptureService::runFailOpen(function () use ($storageId, $emptiedBy): Result {
			$context = AuditOperationContextStack::current();

			$event = new AuditObjectEvent(
				eventCode: AuditEventCode::TrashcanEmptied,
				operationId: (new AuditNormalizer())->resolveOperationId($context),
				source: self::SOURCE,
				actionContext: $context?->actionContext ?? $this->createActionContext($emptiedBy, $context?->channel),
				targetType: AuditEventCodeRegistry::TARGET_TRASHCAN,
				targetId: "storage:{$storageId}:trashcan",
				subject: new AuditSubject(AuditSubject::TYPE_STORAGE, (string)$storageId),
				details: [
					'storageId' => $storageId,
				],
			);

			return $this->send($event);
		});
	}

	private function createActionContext(?int $userId, ?string $channel = null): AuditActionContext
	{
		return (new AuditActionContextResolver())->resolve(
			userId: $userId,
			channel: $channel,
		);
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
