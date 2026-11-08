<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\Responsible;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\WebForm\Internals\QueueTable;

/**
 * @internal
 */
final class ResponsibleSectionLoader extends WebFormSectionLoader
{
	public function getSectionName(): string
	{
		return WebForm::SECTION_RESPONSIBLE;
	}

	/**
	 * @param string[] $selectedFields
	 */
	protected function createEmptySection(array $selectedFields): mixed
	{
		if ($selectedFields === [])
		{
			return [];
		}

		return new Responsible(
			userIds: in_array(Responsible::FIELD_USER_IDS, $selectedFields, true) ? [] : null,
			checkWorkTime: in_array(Responsible::FIELD_CHECK_WORK_TIME, $selectedFields, true)
				? false
				: null,
		);
	}

	/**
	 * @param int[] $formIds
	 * @param string[] $selectedFields
	 * @return array<int, Responsible>
	 */
	protected function loadSelected(array $formIds, array $selectedFields): array
	{
		$userIdsSelected = in_array(Responsible::FIELD_USER_IDS, $selectedFields, true);
		$checkWorkTimeSelected = in_array(Responsible::FIELD_CHECK_WORK_TIME, $selectedFields, true);
		if (!$userIdsSelected && !$checkWorkTimeSelected)
		{
			return [];
		}

		$columns = ['FORM_ID', 'ID'];
		if ($userIdsSelected)
		{
			$columns[] = 'USER_ID';
		}
		if ($checkWorkTimeSelected)
		{
			$columns[] = 'WORK_TIME';
		}

		$query =
			QueueTable::query()
				->setSelect($columns)
				->whereIn('FORM_ID', $formIds)
				->setOrder([
					'FORM_ID' => 'ASC',
					'ID' => 'ASC',
				])
		;

		$formIdsWithRows = [];
		$userIdsByFormId = [];
		$seenUserIdsByFormId = [];
		$checkWorkTimeByFormId = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$formId = (int)$row['FORM_ID'];
			$formIdsWithRows[$formId] = true;

			if ($userIdsSelected)
			{
				$userId = (int)($row['USER_ID'] ?? 0);
				if (!isset($seenUserIdsByFormId[$formId][$userId]))
				{
					$seenUserIdsByFormId[$formId][$userId] = true;
					$userIdsByFormId[$formId][] = $userId;
				}
			}

			if ($checkWorkTimeSelected)
			{
				$checkWorkTimeByFormId[$formId] = self::isYes($row['WORK_TIME'] ?? null);
			}
		}

		$result = [];
		foreach ($formIdsWithRows as $formId => $_)
		{
			$result[$formId] = new Responsible(
				userIds: $userIdsSelected ? ($userIdsByFormId[$formId] ?? []) : null,
				checkWorkTime: $checkWorkTimeSelected ? ($checkWorkTimeByFormId[$formId] ?? false) : null,
			);
		}

		return $result;
	}

	private static function isYes(mixed $value): bool
	{
		return $value === true || $value === 'Y' || $value === 1 || $value === '1';
	}
}
