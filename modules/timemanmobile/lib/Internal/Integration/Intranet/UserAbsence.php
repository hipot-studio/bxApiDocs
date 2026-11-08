<?php

namespace Bitrix\TimemanMobile\Internal\Integration\Intranet;

use Bitrix\Intranet\Internal\Entity\AbsenceType;
use Bitrix\Intranet\Internal\Entity\User\Absence;
use Bitrix\Intranet\Public\Provider\User\AbsenceProvider;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;

final class UserAbsence
{
	public static function isAbsenceAvailable(): bool
	{
		return
			Loader::includeModule('intranet')
			&& Loader::includeModule('iblock')
			&& ToolsManager::getInstance()->checkAvailabilityByToolId('absence');
	}

	/**
	 * @return string[]
	 */
	public static function getAbsenceTypes(): array
	{
		if (!self::isAbsenceAvailable())
		{
			return [];
		}

		return (new AbsenceProvider())->getTypes()->map(fn(AbsenceType $type): string => $type->xmlId);
	}

	/**
	 * @return string[]
	 */
	public static function getAbsenceTypesByDay(int $userId, Date $day): array
	{
		if (!self::isAbsenceAvailable())
		{
			return [];
		}

		$absences = (new AbsenceProvider())->getUserAbsencesByDay($userId, $day);
		if ($absences === null)
		{
			return [];
		}

		return $absences->map(fn(Absence $absence): string => $absence->getTypeXmlId());
	}

	/**
	 * @param int $userId
	 * @param array $absences
	 * @return Result[]
	 */
	public static function addAbsences(int $userId, array $absences): array
	{
		$results = [];

		if (!self::isAbsenceAvailable())
		{
			$results[] = (new Result())->addError(
				new Error('Absence is not available', 'absence_unavailable'),
			);

			return $results;
		}

		$absenceProvider = new AbsenceProvider();

		foreach ($absences as $absence)
		{
			$addResult = $absenceProvider->addUserAbsence(
				$userId,
				$absence['from'],
				$absence['to'],
				$absence['description'],
				$absence['type'],
			);

			if ($addResult->isSuccess())
			{
				/** @var ?Absence $entity */
				$entity = $addResult->getData()['absence'] ?? null;
				$addResult->setData(['absence' => $entity?->toArray()]);
			}

			$results[] = $addResult;
		}

		return $results;
	}
}
