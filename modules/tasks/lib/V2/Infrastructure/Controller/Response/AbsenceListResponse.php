<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Response;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\Absence;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\AbsenceCollection;

class AbsenceListResponse implements Arrayable
{
	public function __construct(
		private readonly AbsenceCollection $absences,
	)
	{
	}

	public function toArray(): array
	{
		$result = [];

		foreach ($this->absences as $absence)
		{
			$result[] = $this->mapFromEntity($absence);
		}

		return $result;
	}

	private function mapFromEntity(Absence $absence): array
	{
		return [
			'id' => $absence->id,
			'userId' => $absence->userId,
			'dateTimeFrom' => $absence->dateTimeFrom,
			'dateTimeTo' => $absence->dateTimeTo,
		];
	}
}
