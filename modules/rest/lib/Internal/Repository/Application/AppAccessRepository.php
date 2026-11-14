<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application;

use Bitrix\Main\Application;
use Bitrix\Main\SystemException;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\Internal\Model\AppAccessTable;
use Bitrix\Rest\Internal\Model\EO_AppAccess_Collection;
use Bitrix\Rest\Internal\Service\Application\AccessCacheInvalidator;

class AppAccessRepository
{
	/** @param int[] $appIds */
	public function getByAppIds(array $appIds): EO_AppAccess_Collection
	{
		if (empty($appIds))
		{
			return AppAccessTable::createCollection();
		}

		return AppAccessTable::query()
			->setSelect(['ID', 'APP_ID', 'ACCESS_CODE'])
			->whereIn('APP_ID', $appIds)
			->setOrder(['ID' => 'ASC'])
			->fetchCollection()
		;
	}

	/**
	 * @return string[]
	 */
	public function getCodes(int $appId): array
	{
		$rows = AppAccessTable::query()
			->setSelect(['ACCESS_CODE'])
			->where('APP_ID', $appId)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		return array_map(static fn(array $row): string => $row['ACCESS_CODE'], $rows);
	}

	/**
	 * @param int[] $appIds
	 * @return array<int, string[]>
	 */
	public function getCodesForApps(array $appIds): array
	{
		if (empty($appIds))
		{
			return [];
		}

		$result = array_fill_keys(array_map('intval', $appIds), []);

		$rows = AppAccessTable::query()
			->setSelect(['APP_ID', 'ACCESS_CODE'])
			->whereIn('APP_ID', $appIds)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$result[(int)$row['APP_ID']][] = $row['ACCESS_CODE'];
		}

		return $result;
	}

	/**
	 * @param string[] $codes
	 */
	public function replaceCodes(int $appId, array $codes): void
	{
		$codes = array_filter($codes, 'is_string');
		$codes = array_map('trim', $codes);
		$codes = array_values(array_unique(array_filter(
			$codes,
			static fn(string $code): bool => $code !== '',
		)));

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			if (!empty($codes))
			{
				$connectionPool = Application::getInstance()->getConnectionPool();
				$connectionPool->useMasterOnly(true);
				try
				{
					$app = AppTable::query()
						->setSelect(['ID'])
						->where('ID', $appId)
						->fetch()
					;
				}
				finally
				{
					$connectionPool->useMasterOnly(false);
				}

				if (!$app)
				{
					throw new SystemException('Unable to save access codes for a missing application');
				}
			}

			AppAccessTable::deleteByAppId($appId);

			if (!empty($codes))
			{
				$rows = array_map(
					static fn(string $code): array => ['APP_ID' => $appId, 'ACCESS_CODE' => $code],
					$codes,
				);
				$result = AppAccessTable::addInsertIgnoreMulti($rows, true);
				if (!$result->isSuccess())
				{
					throw new SystemException(
						'Unable to save app access codes: ' . implode(', ', $result->getErrorMessages())
					);
				}
			}
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		$connection->commitTransaction();
		AppTable::clearClientCache($appId);
		AccessCacheInvalidator::clear();
	}

	public function deleteByAppId(int $appId): void
	{
		$this->replaceCodes($appId, []);
	}
}
