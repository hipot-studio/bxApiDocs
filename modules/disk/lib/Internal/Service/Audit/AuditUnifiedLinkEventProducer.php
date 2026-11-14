<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Dto\Audit\AuditSubject;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use Bitrix\Disk\Public\Event\Audit\AuditLinkEvent;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Main\Result;
use Throwable;

final class AuditUnifiedLinkEventProducer
{
	public const SOURCE = AbstractAuditEvent::SOURCE_UNIFIED_LINK;
	public const ERROR_EVENT_HANDLER_FAILED = 'DISK_AUDIT_UNIFIED_LINK_EVENT_HANDLER_FAILED';

	public function __construct(
		private readonly mixed $sender = null,
	)
	{
	}

	public function publishAccessChanged(
		BaseObject $object,
		UnifiedLinkAccessLevel $oldAccessLevel,
		UnifiedLinkAccessLevel $newAccessLevel,
		?int $changedBy,
	): Result
	{
		return AuditCaptureService::runFailOpen(function () use ($object, $oldAccessLevel, $newAccessLevel, $changedBy): Result {
			if ($oldAccessLevel === $newAccessLevel)
			{
				return new Result();
			}

			$context = AuditOperationContextStack::current();
			$event = new AuditLinkEvent(
				eventCode: AuditEventCode::UnifiedLinkAccessChanged,
				operationId: (new AuditNormalizer())->resolveOperationId($context),
				source: self::SOURCE,
				actionContext: $context?->actionContext ?? $this->createActionContext($changedBy, $context?->channel),
				targetType: AuditEventCodeRegistry::TARGET_UNIFIED_LINK,
				targetId: (string)$object->getId(),
				targetObject: $object,
				subject: new AuditSubject(AuditSubject::TYPE_UNIFIED_LINK, (string)$object->getId()),
				details: [
					'oldAccessLevel' => $oldAccessLevel->value,
					'newAccessLevel' => $newAccessLevel->value,
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

	private function send(AuditLinkEvent $event): Result
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
