<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Service;

use Bitrix\Intranet\UserAbsence;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\Absence;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Entity\AbsenceCollection;
use CIntranetUtils;

class AbsenceService
{
	/**
	 * @param int[] $userIds
	 */
	public function getAbsenceData(array $userIds, ?DateTime $from = null, ?DateTime $to = null): AbsenceCollection
	{
		$rawData = $this->fetchRawAbsenceData($userIds, $from, $to, false);

		if (empty($rawData))
		{
			return new AbsenceCollection();
		}

		$items = array_map(fn (array $item) => $this->mapAbsenceDataItem($item), $rawData);

		return new AbsenceCollection(...$items);
	}

	/**
	 * @param int[] $userIds
	 */
	public function getVacationData(array $userIds, ?DateTime $from = null, ?DateTime $to = null): AbsenceCollection
	{
		$absenceData = $this->getAbsenceData($userIds, $from, $to);

		if ($absenceData->isEmpty())
		{
			return new AbsenceCollection();
		}

		$vacationTypeEnumIds = array_flip($this->getVacationTypeEnumIds());

		return $absenceData->filter(
			static fn (Absence $absenceDataItem) => isset($vacationTypeEnumIds[$absenceDataItem->typeEnumId])
		);
	}

	/**
	 * @param int[] $userIds
	 *
	 * @return array<int, AbsenceCollection>
	 */
	public function getAbsenceDataPerUser(array $userIds, ?DateTime $from = null, ?DateTime $to = null): array
	{
		$rawData = $this->fetchRawAbsenceData($userIds, $from, $to, true);

		if (empty($rawData))
		{
			return [];
		}

		$result = [];
		foreach ($rawData as $userId => $absences)
		{
			$items = [];
			foreach ($absences as $item)
			{
				$items[] = $this->mapAbsenceDataItem($item);
			}
			$result[$userId] = new AbsenceCollection(...$items);
		}

		return $result;
	}

	private function getVacationTypeEnumIds(): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		$vacationTypes = array_filter(UserAbsence::getVacationTypes(), static fn (array $type) => $type['ACTIVE']);

		return array_map('intval', array_column($vacationTypes, 'ENUM_ID'));
	}

	private function fetchRawAbsenceData(
		array $userIds,
		?DateTime $from,
		?DateTime $to,
		bool $perUser
	): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		$params = [
			'USERS' => $userIds,
			'PER_USER' => $perUser,
		];

		if ($from !== null)
		{
			$params['DATE_START'] = $from;
		}

		if ($to !== null)
		{
			$params['DATE_FINISH'] = $to;
		}

		return CIntranetUtils::GetAbsenceData($params);
	}

	private function mapAbsenceDataItem(array $item): Absence
	{
		return new Absence(
			id: (int)$item['ID'],
			userId: (int)$item['PROPERTY_USER_VALUE'],
			typeEnumId: (int)$item['PROPERTY_ABSENCE_TYPE_ENUM_ID'],
			dateTimeFrom: new DateTime($item['DATE_ACTIVE_FROM']),
			dateTimeTo: new DateTime($item['DATE_ACTIVE_TO']),
			name: (string)($item['NAME'] ?? ''),
		);
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('intranet');
	}
}
