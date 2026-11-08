<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox\Avatar;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class AvatarChange
{
	public const OPERATION_REPLACE = 'replace';
	public const OPERATION_DELETE = 'delete';
	public const ERROR_INVALID_CHANGE = 'MAIL_AVATAR_INVALID_CHANGE';

	private function __construct(
		public readonly string $operation,
		public readonly int $expectedRevision,
		public readonly ?string $uploadToken,
	)
	{
	}

	public static function fromArray(mixed $data): Result
	{
		$result = new Result();
		if (!is_array($data) || !self::isValid($data))
		{
			return $result->addError(new Error(self::ERROR_INVALID_CHANGE, self::ERROR_INVALID_CHANGE));
		}

		return $result->setData([
			'change' => new self($data['operation'], $data['expectedRevision'], $data['uploadToken'] ?? null),
		]);
	}

	private static function isValid(array $data): bool
	{
		$operation = $data['operation'] ?? null;
		$revision = $data['expectedRevision'] ?? null;
		if (
			!in_array($operation, [self::OPERATION_REPLACE, self::OPERATION_DELETE], true)
			|| !is_int($revision)
			|| $revision < 0
		)
		{
			return false;
		}

		$keys = ['operation', 'expectedRevision'];
		if ($operation === self::OPERATION_REPLACE)
		{
			$keys[] = 'uploadToken';
			if (!is_string($data['uploadToken'] ?? null) || trim($data['uploadToken']) === '')
			{
				return false;
			}
		}

		return array_diff(array_keys($data), $keys) === [];
	}
}
