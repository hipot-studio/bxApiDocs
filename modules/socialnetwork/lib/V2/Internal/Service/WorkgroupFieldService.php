<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service;

use Bitrix\Socialnetwork\V2\Internal\Entity\Project\ProjectDates;
use CSocNetGroup;

class WorkgroupFieldService
{
	public function saveGroupFields(
		int $id,
		?ProjectDates $dates,
		?bool $publication,
		?string $goal = null,
	): void
	{
		$fields = $this->prepareFields(
			dates: $dates,
			publication: $publication,
			goal: $goal,
		);

		if ($fields === [])
		{
			return;
		}

		$this->updateGroup($id, $fields, false);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	protected function updateGroup(int $id, array $fields, bool $sync): void
	{
		CSocNetGroup::Update(
			ID: $id,
			arFields: $fields,
			bSync: $sync,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function prepareFields(
		?ProjectDates $dates,
		?bool $publication,
		?string $goal = null,
	): array
	{
		$fields = [];

		if ($dates !== null)
		{
			$fields['PROJECT_DATE_START'] = $dates->start ?? '';
			$fields['PROJECT_DATE_FINISH'] = $dates->finish ?? '';
		}

		if ($publication !== null)
		{
			$fields['LANDING'] = $publication ? 'Y' : 'N';
		}

		if ($goal !== null)
		{
			$fields['GOAL'] = $goal;
		}

		return $fields;
	}
}
