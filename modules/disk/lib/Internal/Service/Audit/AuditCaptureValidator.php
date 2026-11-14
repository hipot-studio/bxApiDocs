<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Entity;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

final class AuditCaptureValidator
{
	public const ERROR_INVALID_PAYLOAD = 'DISK_AUDIT_CAPTURE_INVALID_PAYLOAD';
	public const ERROR_FORBIDDEN_DETAIL = 'DISK_AUDIT_CAPTURE_FORBIDDEN_DETAIL';
	public const ERROR_IDEMPOTENCY_KEY_MISMATCH = 'DISK_AUDIT_CAPTURE_IDEMPOTENCY_KEY_MISMATCH';

	/**
	 * @param string[] $defaultAllowedDetailKeys
	 */
	public function __construct(
		private readonly ?AuditIdempotencyKeyBuilder $idempotencyKeyBuilder = null,
		private readonly array $defaultAllowedDetailKeys = [],
	)
	{
	}

	/**
	 * @param string[]|null $allowedDetailKeys
	 */
	public function validate(Entity\Audit\AuditMessage $message, ?array $allowedDetailKeys = null): Result
	{
		$result = new Result();

		if ($message->source === '')
		{
			return $result->addError(
				new Error('Audit source must be resolved before capture.', self::ERROR_INVALID_PAYLOAD),
			);
		}

		$keyResult = $this->validateIdempotencyKey($message);
		if (!$keyResult->isSuccess())
		{
			return $result->addErrors($keyResult->getErrors());
		}

		return $this->validateDetails($message, $allowedDetailKeys ?? $this->defaultAllowedDetailKeys);
	}

	private function validateIdempotencyKey(Entity\Audit\AuditMessage $message): Result
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
				new Error(
					'Audit message idempotency key does not match canonical payload.',
					self::ERROR_IDEMPOTENCY_KEY_MISMATCH,
				),
			);
		}

		return $result;
	}

	/**
	 * @param string[] $allowedDetailKeys
	 */
	private function validateDetails(Entity\Audit\AuditMessage $message, array $allowedDetailKeys): Result
	{
		$result = new Result();
		$allowed = array_fill_keys($allowedDetailKeys, true);

		foreach (array_keys($message->details) as $key)
		{
			if (!isset($allowed[$key]))
			{
				return $result->addError(
					new Error(
						sprintf('Audit detail key "%s" is not allowed for this producer.', $key),
						self::ERROR_FORBIDDEN_DETAIL,
					),
				);
			}
		}

		return $result;
	}

	private function getIdempotencyKeyBuilder(): AuditIdempotencyKeyBuilder
	{
		return $this->idempotencyKeyBuilder ?? new AuditIdempotencyKeyBuilder();
	}
}
