<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Entity;
use Bitrix\Disk\Internal\Messenger;
use Bitrix\Main\Error;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;
use Bitrix\Main\Messenger\Internals\Exception\Broker\SendFailedException;
use Bitrix\Main\Result;
use Throwable;

final class AuditOutboxService
{
	public const QUEUE_ID = 'disk_audit';
	public const ERROR_SEND_FAILED = 'DISK_AUDIT_OUTBOX_SEND_FAILED';

	/**
	 * @param callable|null $sender
	 */
	public function __construct(
		private readonly mixed $sender = null,
	)
	{
	}

	public function enqueue(Entity\Audit\AuditMessage $message): Result
	{
		$result = new Result();
		$messengerMessage = new Messenger\Audit\AuditMessage($message);

		try
		{
			$this->send($messengerMessage, [
				new ItemIdParam($message->idempotencyKey),
			]);
		}
		catch (SendFailedException $exception)
		{
			return $result->addError(
				new Error($exception->getMessage(), self::ERROR_SEND_FAILED),
			);
		}
		catch (Throwable $exception)
		{
			return $result->addError(
				new Error($exception->getMessage(), self::ERROR_SEND_FAILED),
			);
		}

		return $result->setData([
			'queueId' => self::QUEUE_ID,
			'itemId' => $message->idempotencyKey,
		]);
	}

	private function send(Messenger\Audit\AuditMessage $message, array $params): void
	{
		if (is_callable($this->sender))
		{
			($this->sender)($message, self::QUEUE_ID, $params);

			return;
		}

		$message->send(self::QUEUE_ID, $params);
	}
}
