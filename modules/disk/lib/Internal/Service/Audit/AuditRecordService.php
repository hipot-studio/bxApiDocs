<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Entity\Audit\AuditMessage;
use Bitrix\Disk\Internal\Repository\Audit\AuditEventRepository;
use Bitrix\Disk\Internal\Repository\Mapper\AuditEventMapper;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

final class AuditRecordService
{
	public const ERROR_IDEMPOTENCY_KEY_MISMATCH = 'DISK_AUDIT_IDEMPOTENCY_KEY_MISMATCH';
	public const ERROR_INVALID_PAYLOAD = 'DISK_AUDIT_INVALID_PAYLOAD';

	public function __construct(
		private readonly ?AuditEventRepository $repository = null,
		private readonly ?AuditEventMapper $mapper = null,
		private readonly ?AuditIdempotencyKeyBuilder $idempotencyKeyBuilder = null,
	)
	{
	}

	public function record(AuditMessage $message): Result
	{
		$result = new Result();

		try
		{
			$expectedKey = $this->getIdempotencyKeyBuilder()->buildFromMessage($message);
		}
		catch (Throwable $exception)
		{
			return $result->addError(
				new Error($exception->getMessage(), self::ERROR_INVALID_PAYLOAD),
			);
		}

		if ($message->idempotencyKey !== $expectedKey)
		{
			return $result->addError(
				new Error('Audit message idempotency key does not match canonical payload.', self::ERROR_IDEMPOTENCY_KEY_MISMATCH),
			);
		}

		try
		{
			$fields = $this->getMapper()->convertToFields($message);
		}
		catch (Throwable $exception)
		{
			return $result->addError(
				new Error($exception->getMessage(), self::ERROR_INVALID_PAYLOAD),
			);
		}

		return $this->getRepository()->add($fields);
	}

	private function getRepository(): AuditEventRepository
	{
		return $this->repository ?? new AuditEventRepository();
	}

	private function getMapper(): AuditEventMapper
	{
		return $this->mapper ?? new AuditEventMapper();
	}

	private function getIdempotencyKeyBuilder(): AuditIdempotencyKeyBuilder
	{
		return $this->idempotencyKeyBuilder ?? new AuditIdempotencyKeyBuilder();
	}
}
