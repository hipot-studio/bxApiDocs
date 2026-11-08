<?php

namespace Bitrix\Main\Cli\Validation;

use Bitrix\Main\IO\Path;
use InvalidArgumentException;

final class LogicalCachePathValidator
{
	public static function validate(string $path): string
	{
		if ($path === '')
		{
			throw new InvalidArgumentException(
				'Specify a non-empty cache path',
			);
		}

		if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1)
		{
			throw new InvalidArgumentException(
				'Cache path must not contain control characters',
			);
		}

		$logicalPath = str_replace('\\', '/', $path);
		if (Path::isAbsolute($logicalPath))
		{
			throw new InvalidArgumentException(
				'Specify a relative cache path',
			);
		}

		foreach (explode('/', $logicalPath) as $segment)
		{
			if ($segment === '')
			{
				throw new InvalidArgumentException(
					'Cache path must not contain empty segments',
				);
			}

			if ($segment === '.' || $segment === '..')
			{
				throw new InvalidArgumentException(
					'Cache path must not contain "." or ".." segments',
				);
			}
		}

		return Path::normalize($logicalPath);
	}
}
