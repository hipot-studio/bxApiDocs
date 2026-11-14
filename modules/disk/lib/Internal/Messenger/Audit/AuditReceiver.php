<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Messenger\Audit;

use Bitrix\Disk\Internal\Repository\Audit\AuditEventRepository;
use Bitrix\Disk\Internal\Service\Audit\AuditRecordService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

final class AuditReceiver extends AbstractReceiver
{
	private const INVALID_ERROR_CODES = [
		AuditRecordService::ERROR_IDEMPOTENCY_KEY_MISMATCH => true,
		AuditRecordService::ERROR_INVALID_PAYLOAD => true,
		AuditEventRepository::ERROR_DUPLICATE_PAYLOAD_MISMATCH => true,
	];

	public function __construct(
		private readonly ?AuditRecordService $recordService = null,
	)
	{
	}

	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof AuditMessage)
		{
			throw new UnprocessableMessageException($message, 'Unsupported audit messenger payload.');
		}

		$result = $this->getRecordService()->record($message->getPayload());
		if ($result->isSuccess())
		{
			return;
		}

		$error = $result->getError();
		if ($this->isInvalidPayloadError($error))
		{
			throw new UnprocessableMessageException(
				$message,
				$error?->getMessage() ?? 'Invalid audit messenger payload.',
			);
		}

		throw new RecoverableMessageException(
			$error?->getMessage() ?? 'Unable to record audit message.',
		);
	}

	private function getRecordService(): AuditRecordService
	{
		return $this->recordService ?? ServiceLocator::getInstance()->get(AuditRecordService::class);
	}

	private function isInvalidPayloadError(?Error $error): bool
	{
		return isset(self::INVALID_ERROR_CODES[$error?->getCode()]);
	}
}
