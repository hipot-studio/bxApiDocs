<?php

namespace Bitrix\Mobile\Internal\Services\Project;

use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\WorkgroupTable;

class ProjectDatesReader
{
	/**
	 * @return array{startTs: ?int, finishTs: ?int}|null null when the project cannot be read
	 */
	public function getTimestamps(int $projectId): ?array
	{
		$fields = $this->fetchFields($projectId);
		if ($fields === null)
		{
			return null;
		}

		return [
			'startTs' => $this->mapToTimestamp($fields['PROJECT_DATE_START'] ?? null),
			'finishTs' => $this->mapToTimestamp($fields['PROJECT_DATE_FINISH'] ?? null),
		];
	}

	protected function fetchFields(int $projectId): ?array
	{
		if ($projectId <= 0)
		{
			return null;
		}

		return WorkgroupTable::getRow([
			'select' => ['PROJECT_DATE_START', 'PROJECT_DATE_FINISH'],
			'filter' => ['=ID' => $projectId],
		]);
	}

	private function mapToTimestamp(mixed $date): ?int
	{
		return $date instanceof DateTime ? $date->getTimestamp() : null;
	}
}
