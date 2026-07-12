<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\ViewedAbsence;
use Bitrix\Tasks\V2\Internal\Entity\ViewedAbsenceCollection;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\Absence;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Trait\CastTrait;

class ViewedAbsenceMapper
{
	use CastTrait;

	public function mapToEntity(array $row): ViewedAbsence
	{
		return ViewedAbsence::mapFromArray([
			'id' => (int)($row['ID'] ?? 0),
			'viewedBy' => (int)($row['VIEWED_BY'] ?? 0),
			'userId' => (int)($row['USER_ID'] ?? 0),
			'absenceId' => (int)($row['ABSENCE_ID'] ?? 0),
			'absenceEnd' => $this->castTimestamp($row['ABSENCE_END']?->getTimestamp() ?? null, false),
		]);
	}

	public function mapToCollection(array $rows): ViewedAbsenceCollection
	{
		$collection = new ViewedAbsenceCollection();

		foreach ($rows as $row)
		{
			$collection->add($this->mapToEntity($row));
		}

		return $collection;
	}

	public function mapFromAbsence(Absence $absence, int $viewedBy): ViewedAbsence
	{
		return new ViewedAbsence(
			id: null,
			viewedBy: $viewedBy,
			userId: $absence->userId,
			absenceId: $absence->id,
			absenceEnd: $absence->dateTimeTo,
		);
	}

	public function mapToRow(ViewedAbsence $entity): array
	{
		return [
			'VIEWED_BY' => $entity->viewedBy,
			'USER_ID' => $entity->userId,
			'ABSENCE_ID' => $entity->absenceId,
			'ABSENCE_END' => $entity->absenceEnd,
		];
	}
}
