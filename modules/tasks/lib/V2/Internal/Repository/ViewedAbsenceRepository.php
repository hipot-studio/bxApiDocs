<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\ViewedAbsenceCollection;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\AbsenceCollection;
use Bitrix\Tasks\V2\Internal\Model\ViewedAbsenceTable;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\ViewedAbsenceMapper;

class ViewedAbsenceRepository implements ViewedAbsenceRepositoryInterface
{
	public function __construct(
		private readonly ViewedAbsenceMapper $mapper,
	)
	{
	}

	public function findByViewedByAndUserIds(int $currentUserId, array $userIds): ViewedAbsenceCollection
	{
		if (empty($userIds))
		{
			return new ViewedAbsenceCollection();
		}

		$rows = ViewedAbsenceTable::query()
			->setSelect(['*'])
			->where('VIEWED_BY', $currentUserId)
			->whereIn('USER_ID', $userIds)
			->fetchAll()
		;

		return $this->mapper->mapToCollection($rows);
	}

	public function save(AbsenceCollection $vacationData, int $userId): void
	{
		$rows = [];
		foreach ($vacationData as $absence)
		{
			$viewedAbsence = $this->mapper->mapFromAbsence($absence, $userId);
			$rows[] = $this->mapper->mapToRow($viewedAbsence);
		}
		ViewedAbsenceTable::addInsertIgnoreMulti($rows);
	}

	public function deleteTill(DateTime $dateTime): void
	{
		ViewedAbsenceTable::deleteByFilter(['<ABSENCE_END', $dateTime]);
	}
}
