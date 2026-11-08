<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Sharepoint;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Throwable;

final class ServerAllowList
{
	private const CACHE_TTL = 3600;

	/**
	 * @return ServerOrigin[]
	 */
	public static function parse(string $raw): array
	{
		$origins = [];
		$lines = preg_split('/\\R/', $raw);
		if ($lines === false)
		{
			throw new ArgumentException('Unable to parse the allowlist.');
		}

		foreach ($lines as $index => $line)
		{
			$line = trim($line);
			if ($line === '')
			{
				continue;
			}

			try
			{
				$origin = ServerOrigin::fromAllowListEntry($line);
			}
			catch (ArgumentException $exception)
			{
				throw new ArgumentException(
					'Invalid origin on line ' . ($index + 1) . ': ' . $exception->getMessage(),
					'',
					$exception,
				);
			}

			$origins[$origin->toString()] = $origin;
		}

		return array_values($origins);
	}

	/**
	 * @return string[]
	 */
	public static function list(): array
	{
		$rows = AllowedOriginTable::getList([
			'select' => ['ORIGIN'],
			'order' => ['ORIGIN' => 'ASC'],
			'cache' => ['ttl' => self::CACHE_TTL],
		])->fetchAll();

		return array_column($rows, 'ORIGIN');
	}

	public static function replace(string $raw): void
	{
		$origins = [];
		foreach (self::parse($raw) as $origin)
		{
			$origins[$origin->toString()] = $origin;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$rows = AllowedOriginTable::getList([
				'select' => ['ID', 'ORIGIN'],
			])->fetchAll();
			$currentOrigins = [];
			foreach ($rows as $row)
			{
				$currentOrigins[$row['ORIGIN']] = (int)$row['ID'];
			}

			foreach (array_diff_key($currentOrigins, $origins) as $id)
			{
				$result = AllowedOriginTable::delete($id);
				if (!$result->isSuccess())
				{
					throw new SystemException(implode('; ', $result->getErrorMessages()));
				}
			}

			foreach (array_diff_key($origins, $currentOrigins) as $origin)
			{
				self::insert($origin);
			}

			$connection->commitTransaction();
			AllowedOriginTable::cleanCache();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();
			throw $exception;
		}
	}

	private static function insert(ServerOrigin $origin): void
	{
		$value = $origin->toString();
		$result = AllowedOriginTable::add(['ORIGIN' => $value]);

		if (!$result->isSuccess())
		{
			throw new SystemException(implode('; ', $result->getErrorMessages()));
		}
	}

	public static function isAllowed(string|array $url): bool
	{
		try
		{
			$origin = ServerOrigin::fromUrl($url);
		}
		catch (ArgumentException)
		{
			return false;
		}

		return self::contains($origin->toString());
	}

	private static function contains(string $origin): bool
	{
		return in_array($origin, self::list(), true);
	}
}
