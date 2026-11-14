<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Dto\Audit\AuditActionContext;
use Bitrix\Disk\Internal\Dto\Audit\AuditSubject;
use Bitrix\Disk\Internal\Enum\Audit\AuditEventCode;
use Bitrix\Disk\Public\Event\Audit\AbstractAuditEvent;
use Bitrix\Disk\Public\Event\Audit\AuditLinkEvent;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Main\Result;
use Throwable;

final class AuditPublicLinkEventProducer
{
	public const SOURCE = AbstractAuditEvent::SOURCE_PUBLIC_LINK;
	public const ERROR_EVENT_HANDLER_FAILED = 'DISK_AUDIT_PUBLIC_LINK_EVENT_HANDLER_FAILED';

	public function __construct(
		private readonly mixed $sender = null,
	)
	{
	}

	public function publishCreated(ExternalLink $link, ?int $createdBy = null): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendLinkEvent(
			link: $link,
			eventCode: AuditEventCode::PublicLinkCreated,
			userId: $createdBy ?? (int)$link->getCreatedBy(),
			details: [
				'accessRight' => $link->getAccessRight(),
				'ttlSet' => $link->hasDeathTime(),
				'passwordEnabled' => $link->hasPassword(),
				'canEditSettings' => $link->canEditSettings(),
			],
		));
	}

	public function publishTtlChanged(ExternalLink $link, ?int $oldTtl, ?int $newTtl, ?int $changedBy = null): Result
	{
		return AuditCaptureService::runFailOpen(function () use ($link, $oldTtl, $newTtl, $changedBy): Result {
			if ($oldTtl === $newTtl)
			{
				return new Result();
			}

			return $this->sendLinkEvent(
				link: $link,
				eventCode: AuditEventCode::PublicLinkTtlChanged,
				userId: $changedBy,
				details: [
					'oldTtl' => $oldTtl,
					'newTtl' => $newTtl,
				],
			);
		});
	}

	public function publishPasswordEnabled(ExternalLink $link, ?int $changedBy = null): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendLinkEvent(
			link: $link,
			eventCode: AuditEventCode::PublicLinkPasswordEnabled,
			userId: $changedBy,
			details: [
				'passwordEnabled' => true,
			],
		));
	}

	public function publishPasswordDisabled(ExternalLink $link, ?int $changedBy = null): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendLinkEvent(
			link: $link,
			eventCode: AuditEventCode::PublicLinkPasswordDisabled,
			userId: $changedBy,
			details: [
				'passwordEnabled' => false,
			],
		));
	}

	public function publishAccessChanged(
		ExternalLink $link,
		int $oldAccessRight,
		int $newAccessRight,
		?bool $canDownloadWithReadAccess = null,
		?int $changedBy = null,
	): Result
	{
		return AuditCaptureService::runFailOpen(function () use (
			$link,
			$oldAccessRight,
			$newAccessRight,
			$canDownloadWithReadAccess,
			$changedBy,
		): Result {
			if ($oldAccessRight === $newAccessRight && $canDownloadWithReadAccess === null)
			{
				return new Result();
			}
			$canDownloadWithReadAccess ??= $link->isCanDownloadWithReadAccess();

			return $this->sendLinkEvent(
				link: $link,
				eventCode: AuditEventCode::PublicLinkAccessChanged,
				userId: $changedBy,
				details: [
					'oldAccessRight' => $oldAccessRight,
					'newAccessRight' => $newAccessRight,
					'canDownloadWithReadAccess' => $canDownloadWithReadAccess,
				],
			);
		});
	}

	public function publishDisabled(ExternalLink $link, ?int $disabledBy = null): Result
	{
		return AuditCaptureService::runFailOpen(fn(): Result => $this->sendLinkEvent(
			link: $link,
			eventCode: AuditEventCode::PublicLinkDisabled,
			userId: $disabledBy,
			details: [
				'disabled' => true,
			],
		));
	}

	private function sendLinkEvent(
		ExternalLink $link,
		AuditEventCode $eventCode,
		?int $userId,
		array $details,
	): Result
	{
		$context = AuditOperationContextStack::current();
		$event = new AuditLinkEvent(
			eventCode: $eventCode,
			operationId: (new AuditNormalizer())->resolveOperationId($context),
			source: self::SOURCE,
			actionContext: $context?->actionContext ?? $this->createActionContext($userId, $context?->channel),
			targetType: AuditEventCodeRegistry::TARGET_PUBLIC_LINK,
			targetId: (string)$link->getId(),
			targetObject: $link->getObject(),
			subject: new AuditSubject(AuditSubject::TYPE_PUBLIC_LINK, (string)$link->getId()),
			details: $details,
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
