<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Sender\Avatar;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class SenderAvatarChange
{
	public const OPERATION_REPLACE = 'replace';
	public const OPERATION_DELETE = 'delete';
	public const ERROR_INVALID_CHANGE = 'MAIL_SENDER_AVATAR_INVALID_CHANGE';

	private function __construct(
		public readonly string $operation,
		public readonly int $expectedRevision,
		public readonly ?string $uploadToken,
	)
	{
	}

	public static function fromArray(mixed $data): Result
	{
		$revision = is_array($data)
			? self::normalizeRevision($data['expectedRevision'] ?? null)
			: null
		;
		if (!is_array($data) || $revision === null || !self::isValid($data))
		{
			return (new Result())->addError(new Error(self::ERROR_INVALID_CHANGE, self::ERROR_INVALID_CHANGE));
		}

		return (new Result())->setData([
			'change' => new self($data['operation'], $revision, $data['uploadToken'] ?? null),
		]);
	}

	private static function isValid(array $data): bool
	{
		$operation = $data['operation'] ?? null;
		if (!in_array($operation, [self::OPERATION_REPLACE, self::OPERATION_DELETE], true))
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

	private static function normalizeRevision(mixed $revision): ?int
	{
		if (is_int($revision))
		{
			return $revision >= 0 ? $revision : null;
		}
		if (!is_string($revision))
		{
			return null;
		}
		if ($revision === '0')
		{
			return 0;
		}

		$max = (string)PHP_INT_MAX;
		if (strlen($revision) > strlen($max) || preg_match('/^[1-9][0-9]*$/D', $revision) !== 1)
		{
			return null;
		}
		if (strlen($revision) === strlen($max) && strcmp($revision, $max) > 0)
		{
			return null;
		}

		return (int)$revision;
	}
}
