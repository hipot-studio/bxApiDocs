<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File;

use Bitrix\Im\Model\FileParamTable;
use Bitrix\Im\V2\Entity\File\Param\BaseParam;
use Bitrix\Im\V2\Entity\File\Param\Param;
use Bitrix\Im\V2\Entity\File\Param\ParamName;
use Bitrix\Im\V2\Message\Forward\FileCopyMap;
use Bitrix\Im\V2\Registry;
use Bitrix\Main\Application;
use Bitrix\Main\SystemException;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<int,Param>
 */
final class ParamCollection extends Registry
{
	private const WRITE_LOCK_PREFIX = 'im_file_params_';
	private const WRITE_LOCK_TIMEOUT_SECONDS = 10;

	private int $fileId;

	private static array $cache = [];

	private function __construct(int $fileId)
	{
		parent::__construct();
		$this->fileId = $fileId;
	}

	public static function getInstance(int $fileId): self
	{
		if (!isset(self::$cache[$fileId]))
		{
			self::load($fileId);
		}

		return self::$cache[$fileId];
	}

	private static function load(int $fileId): void
	{
		$collection = new self($fileId);

		$result = FileParamTable::query()
			->setSelect(['ID', 'DISK_FILE_ID', 'PARAM_NAME', 'PARAM_VALUE'])
			->where('DISK_FILE_ID', $fileId)
			->fetchAll()
		;

		self::$cache[$fileId] = $collection->initByArray($result);
	}

	public static function loadByFileIds(array $fileIds): void
	{
		foreach ($fileIds as $key => $fileId)
		{
			if (isset(self::$cache[$fileId]))
			{
				unset($fileIds[$key]);
			}
		}

		if (empty($fileIds))
		{
			return;
		}

		$result = FileParamTable::query()
			->setSelect(['ID', 'DISK_FILE_ID', 'PARAM_NAME', 'PARAM_VALUE'])
			->whereIn('DISK_FILE_ID', $fileIds)
			->exec()
		;

		$params = [];
		while ($row = $result->fetch())
		{
			$params[$row['DISK_FILE_ID']][] = $row;
		}

		foreach ($fileIds as $fileId)
		{
			$collection = new self((int)$fileId);
			self::$cache[$fileId] = $collection->initByArray($params[$fileId] ?? []);
		}
	}

	private function initByArray(array $items): self
	{
		foreach ($items as $item)
		{
			$paramName = $item['PARAM_NAME'] ?? '';

			if (
				$this->offsetExists($paramName)
				|| ParamName::tryFrom($paramName) === null
			)
			{
				continue;
			}

			$this[$paramName] = BaseParam::getInstance(
				(int)$item['DISK_FILE_ID'] ,
				ParamName::tryFrom($paramName),
				$item['PARAM_VALUE']
			);
		}

		return $this;
	}

	public static function addParams(array $params): void
	{
		$batches = [];
		foreach ($params as $param)
		{
			if (
				ParamName::tryFrom((string)$param['PARAM_NAME']) === null
				|| empty($param['DISK_FILE_ID'])
			)
			{
				continue;
			}

			$fileId = (int)$param['DISK_FILE_ID'];
			$batches[$fileId][] = [
				'DISK_FILE_ID' => $param['DISK_FILE_ID'],
				'PARAM_NAME' => $param['PARAM_NAME'],
				'PARAM_VALUE' => $param['PARAM_VALUE'] ?? null,
			];
		}

		foreach ($batches as $fileId => $batch)
		{
			self::addFileParams($fileId, $batch);
		}
	}

	private static function addFileParams(int $fileId, array $params): void
	{
		$application = Application::getInstance();
		$connection = $application->getConnection();
		$connectionPool = $application->getConnectionPool();
		$lockName = self::WRITE_LOCK_PREFIX . $fileId;
		$isLocked = false;

		$connectionPool->useMasterOnly(true);
		try
		{
			$isLocked = $connection->lock($lockName, self::WRITE_LOCK_TIMEOUT_SECONDS);
			if (!$isLocked)
			{
				throw new SystemException("Could not lock file parameters for file {$fileId}.");
			}

			unset(self::$cache[$fileId]);
			$params = self::excludeConflictingParams($params);

			foreach ($params as $param)
			{
				self::$cache[$fileId]->initByArray([$param]);
			}

			self::insertParams($params);
		}
		catch (\Throwable $error)
		{
			unset(self::$cache[$fileId]);

			throw $error;
		}
		finally
		{
			try
			{
				if ($isLocked)
				{
					$connection->unlock($lockName);
				}
			}
			finally
			{
				$connectionPool->useMasterOnly(false);
			}
		}
	}

	/**
	 * A file cannot keep conflicting parameters at once, whatever operation wrote them: the ones
	 * pushed out are dropped from the batch and deleted from the ones already stored.
	 */
	private static function excludeConflictingParams(array $validParams): array
	{
		$batches = [];
		foreach ($validParams as $validParam)
		{
			$batches[(int)$validParam['DISK_FILE_ID']][(string)$validParam['PARAM_NAME']] = $validParam;
		}

		if ($batches === [])
		{
			return [];
		}

		self::loadByFileIds(array_keys($batches));

		$remainingParams = [];
		foreach ($batches as $fileId => $batch)
		{
			$conflictingNames = self::getConflictingParamNames($fileId, $batch);
			self::deleteStoredParams($fileId, $conflictingNames);

			foreach (array_diff_key($batch, array_flip($conflictingNames)) as $param)
			{
				$remainingParams[] = $param;
			}
		}

		return $remainingParams;
	}

	/**
	 * The stored value wins over the one being written - the insert keeps the row that is there -
	 * so the conflict is judged by the parameters the file has after the write.
	 *
	 * @param array<string, array> $batch
	 * @return string[]
	 */
	private static function getConflictingParamNames(int $fileId, array $batch): array
	{
		$resultingParams = self::getInstance($fileId)->getArrayCopy();

		foreach ($batch as $paramName => $param)
		{
			$resultingParams[$paramName] ??= BaseParam::getInstance(
				$fileId,
				ParamName::from($paramName),
				(string)$param['PARAM_VALUE']
			);
		}

		$conflictingNames = [];
		foreach ($resultingParams as $param)
		{
			foreach ($param->getConflictingParams() as $conflictingParam)
			{
				$conflictingNames[$conflictingParam->value] = true;
			}
		}

		return array_keys($conflictingNames);
	}

	/**
	 * @param string[] $paramNames
	 */
	private static function deleteStoredParams(int $fileId, array $paramNames): void
	{
		$collection = self::getInstance($fileId);

		$storedNames = [];
		foreach ($paramNames as $paramName)
		{
			if (isset($collection[$paramName]))
			{
				$storedNames[] = $paramName;
			}
		}

		if ($storedNames === [])
		{
			return;
		}

		$collection->unsetByKeys($storedNames);

		FileParamTable::deleteBatch([
			'=DISK_FILE_ID' => $fileId,
			'=PARAM_NAME' => $storedNames,
		]);
	}

	private static function insertParams(array $fields): void
	{
		if (empty($fields))
		{
			return;
		}

		FileParamTable::multiplyInsertWithoutDuplicate(
			$fields,
			['UNIQUE_FIELDS' => ['DISK_FILE_ID', 'PARAM_NAME']]
		);
	}

	/**
	 * Deletes every param of the given disk files.
	 *
	 * The id of a deleted disk file comes back into use while its params stay in the table, so a file may be
	 * born carrying the flags of its predecessor. Only a caller that knows the file is a new object may call
	 * this: for a file that already lives in a chat its params are its own data.
	 *
	 * @param int[] $fileIds
	 */
	public static function deleteParams(array $fileIds): void
	{
		$fileIds = array_filter(array_map('intval', $fileIds), static fn (int $fileId): bool => $fileId > 0);
		$fileIds = array_values(array_unique($fileIds));

		if (empty($fileIds))
		{
			return;
		}

		FileParamTable::deleteBatch(['=DISK_FILE_ID' => $fileIds]);

		foreach ($fileIds as $fileId)
		{
			unset(self::$cache[$fileId]);
		}
	}

	public function getParam(ParamName $param): ?Param
	{
		return $this[$param->value] ?? null;
	}

	public function getFileId(): int
	{
		return $this->fileId;
	}

	public static function copyParams(FileCopyMap $fileCopyMap): void
	{
		self::loadByFileIds($fileCopyMap->getOldFileIds());

		$insertParams = [];
		foreach ($fileCopyMap->getFileMap() as $copyFileId => $oldFileId)
		{
			$oldCollection = self::getInstance($oldFileId);
			foreach ($oldCollection as $item)
			{
				$newParam = $item->toArray();
				$newParam['DISK_FILE_ID'] = $copyFileId;
				$insertParams[] = $newParam;
			}
		}

		self::insertParams($insertParams);
	}
}
