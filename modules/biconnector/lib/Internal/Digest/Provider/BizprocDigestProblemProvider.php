<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Provider;

use Bitrix\Bizproc\Public\Provider\StorageItemProvider;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Main\Loader;

/**
 * Reads the problems of one digest run from the storage of its process.
 *
 * The template writes a record of its own for each problem, tagged with the run: adding one value to a
 * shared multiple field makes the storage rewrite all values of the record, which costs a square of the
 * number of problems. Records are read in pages by id, so a run with thousands of problems is read at
 * linear cost and with bounded memory for the storage.
 */
final class BizprocDigestProblemProvider
{
	public const RECORD_KIND = 'PROBLEM';

	public function __construct(private readonly int $pageSize = 500)
	{
	}

	/**
	 * @return list<mixed> Values of the problem field, in the order of the records.
	 */
	public function findRunProblems(string $storageCode, string $instanceId): array
	{
		if ($storageCode === '' || $instanceId === '' || !Loader::includeModule('bizproc'))
		{
			return [];
		}

		$storageType = (new StorageTypeProvider())->getByCode($storageCode);
		$storageTypeId = (int)$storageType?->getId();
		if ($storageTypeId <= 0)
		{
			return [];
		}

		$provider = new StorageItemProvider($storageTypeId);
		$problems = [];
		$lastId = 0;
		do
		{
			$items = $provider->getItems([
				'filter' => [
					'>ID' => $lastId,
					'=RecordKind' => self::RECORD_KIND,
					'=InstanceId' => $instanceId,
				],
				'order' => ['ID' => 'ASC'],
				'limit' => $this->pageSize,
			]);

			$count = 0;
			foreach ($items ?? [] as $item)
			{
				$count++;
				$lastId = (int)$item->getId();
				$problems[] = $item->getValueField('Problem');
			}
		}
		while ($count === $this->pageSize);

		return $problems;
	}
}
