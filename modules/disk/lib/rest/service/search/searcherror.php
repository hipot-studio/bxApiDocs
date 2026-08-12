<?php

declare(strict_types=1);

namespace Bitrix\Disk\Rest\Service\Search;

use Bitrix\Disk\Internals\Error\Error;

final class SearchError extends Error
{
	public const INVALID_QUERY = 'INVALID_QUERY';
	public const INVALID_TYPE = 'INVALID_TYPE';
	public const INVALID_FILTER = 'INVALID_FILTER';
	public const NOT_FOUND = 'NOT_FOUND';
	public const UNSUPPORTED_STORAGE = 'UNSUPPORTED_STORAGE';

	public static function invalidQuery(): self
	{
		return new self('Search query is invalid.', self::INVALID_QUERY);
	}

	public static function invalidType(): self
	{
		return new self('Search result type is invalid.', self::INVALID_TYPE);
	}

	public static function invalidFilter(): self
	{
		return new self('Search filter contains an unknown field.', self::INVALID_FILTER);
	}

	public static function notFound(): self
	{
		return new self('Search scope was not found.', self::NOT_FOUND);
	}

	public static function unsupportedStorage(): self
	{
		return new self('Search is not supported for this storage.', self::UNSUPPORTED_STORAGE);
	}
}
