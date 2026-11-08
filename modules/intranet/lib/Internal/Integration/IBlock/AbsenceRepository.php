<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\IBlock;

use Bitrix\Intranet\Internal\Entity\AbsenceType;
use Bitrix\Intranet\Internal\Entity\User\Absence;
use Bitrix\Intranet\Internal\Enum\AbsenceTypeCode;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class AbsenceRepository
{
	private static ?AbsenceRepository $instance = null;
	private static ?array $activeVacationTypes = null;
	private bool $isModuleInstalled;
	/**
	 * @var array(int, EntityCollection(\Bitrix\Intranet\Internal\Entity\AbsenceType)
	 */
	private array $availableTypesByIblockId = [];

	public function __construct()
	{
		$this->isModuleInstalled = Loader::includeModule('iblock');
	}

	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	public function getCollection(int $userId, int $iblockId, DateTime $dateFrom, DateTime $dateTo): EntityCollection
	{
		$collection = new EntityCollection();

		if (!$this->isModuleInstalled)
		{
			return $collection;
		}

		$absenceTypeEnumXmlId = $this->getTypeXmlIdsByEnumId($iblockId);

		$res = \CIBlockElement::GetList(
			[],
			[
				'IBLOCK_ID' => $iblockId,
				'ACTIVE' => 'Y',
				'PROPERTY_USER' => $userId,
				[
					'LOGIC' => 'AND',
					['<=DATE_ACTIVE_FROM' => $dateTo->toString()],
					['>=DATE_ACTIVE_TO' => $dateFrom->toString()],
				]
			],
			false,
			false,
			['ID', 'NAME', 'ACTIVE_FROM', 'ACTIVE_TO', 'PROPERTY_ABSENCE_TYPE'],
		);

		while ($absence = $res->Fetch())
		{
			$collection->add(self::createAbsence($userId, $absence, $absenceTypeEnumXmlId));
		}

		return $collection;
	}

	public function getCollectionOfEachUser(
		array $userIds,
		int $iblockId,
		DateTime $dateFrom,
		DateTime $dateTo,
	): array
	{
		$collections = self::createEmptyCollections($userIds);
		if ($userIds === [] || !$this->isModuleInstalled)
		{
			return $collections;
		}

		$absenceTypeEnumXmlId = $this->getTypeXmlIdsByEnumId($iblockId);

		foreach ($this->fetchRowsByUserId($userIds, $iblockId, $dateFrom, $dateTo) as $userId => $row)
		{
			$collections[$userId]->add(self::createAbsence($userId, $row, $absenceTypeEnumXmlId));
		}

		return $collections;
	}

	/**
	 * Loads absence collections for multiple users in a single query.
	 *
	 * @param int[] $userIds
	 * @return array{
	 *     collections: array<int, EntityCollection>,
	 *     untrustedUserIds: array<int, true>
	 * }
	 */
	public function getCollectionsByUserIds(
		array $userIds,
		int $iblockId,
		DateTime $dateFrom,
		DateTime $dateTo,
	): array
	{
		$collections = self::createEmptyCollections($userIds);
		if ($userIds === [])
		{
			return [
				'collections' => $collections,
				'untrustedUserIds' => [],
			];
		}
		if (!$this->isModuleInstalled)
		{
			throw new \RuntimeException('Module iblock is not installed.');
		}

		$absenceTypeEnumXmlId = $this->getTypeXmlIdsByEnumId($iblockId);

		$untrustedUserIds = [];
		foreach ($this->fetchRowsByUserId($userIds, $iblockId, $dateFrom, $dateTo) as $userId => $row)
		{
			if (isset($untrustedUserIds[$userId]))
			{
				continue;
			}

			try
			{
				$typeEnumId = (string)($row['PROPERTY_ABSENCE_TYPE_ENUM_ID'] ?? '');
				$typeXmlId = $absenceTypeEnumXmlId[$typeEnumId] ?? null;
				if (
					$typeXmlId === null
					|| !is_string($row['ACTIVE_FROM'] ?? null)
					|| $row['ACTIVE_FROM'] === ''
					|| !is_string($row['ACTIVE_TO'] ?? null)
					|| $row['ACTIVE_TO'] === ''
				)
				{
					throw new \UnexpectedValueException('Invalid absence row.');
				}

				$collections[$userId]->add(new Absence(
					$userId,
					new DateTime($row['ACTIVE_FROM']),
					new DateTime($row['ACTIVE_TO']),
					(string)($row['NAME'] ?? ''),
					$typeXmlId,
					self::getTypeCaption($typeXmlId),
					(int)($row['ID'] ?? 0),
					self::dateContainsTime($row['ACTIVE_TO']),
				));
			}
			catch (\Throwable)
			{
				$untrustedUserIds[$userId] = true;
				$collections[$userId] = new EntityCollection();
			}
		}

		return [
			'collections' => $collections,
			'untrustedUserIds' => $untrustedUserIds,
		];
	}

	private static function createEmptyCollections(array $userIds): array
	{
		$collections = [];
		foreach ($userIds as $userId)
		{
			$collections[$userId] = new EntityCollection();
		}

		return $collections;
	}

	private function fetchRowsByUserId(
		array $userIds,
		int $iblockId,
		DateTime $dateFrom,
		DateTime $dateTo,
	): iterable
	{
		$isRequestedUserId = array_fill_keys($userIds, true);
		$rows = $this->fetchCollectionsRows($userIds, $iblockId, $dateFrom, $dateTo);
		while ($row = $rows->Fetch())
		{
			$userId = $this->extractUserId($row['PROPERTY_USER_VALUE'] ?? null);
			if (isset($isRequestedUserId[$userId]))
			{
				yield $userId => $row;
			}
		}
	}

	protected function fetchCollectionsRows(
		array $userIds,
		int $iblockId,
		DateTime $dateFrom,
		DateTime $dateTo,
	): object
	{
		return \CIBlockElement::GetList(
			[],
			[
				'IBLOCK_ID' => $iblockId,
				'ACTIVE' => 'Y',
				'PROPERTY_USER' => $userIds,
				[
					'LOGIC' => 'AND',
					['<=DATE_ACTIVE_FROM' => $dateTo->toString()],
					['>=DATE_ACTIVE_TO' => $dateFrom->toString()],
				],
			],
			false,
			false,
			[
				'ID',
				'NAME',
				'ACTIVE_FROM',
				'ACTIVE_TO',
				'PROPERTY_USER',
				'PROPERTY_ABSENCE_TYPE',
			],
		);
	}

	private function extractUserId(mixed $value): int
	{
		if (is_array($value))
		{
			$value = reset($value);
		}

		return is_numeric($value) ? (int)$value : 0;
	}

	private static function createAbsence(int $userId, array $row, array $absenceTypeEnumXmlId): Absence
	{
		$xmlId = $absenceTypeEnumXmlId[$row['PROPERTY_ABSENCE_TYPE_ENUM_ID']] ?? '';

		return new Absence(
			$userId,
			new DateTime($row['ACTIVE_FROM']),
			new DateTime($row['ACTIVE_TO']),
			$row['NAME'],
			$xmlId,
			self::getTypeCaption($xmlId),
			(int)$row['ID'],
			self::dateContainsTime($row['ACTIVE_TO']),
		);
	}

	public function set(
		int $iblockId,
		Absence $absence,
	): Result
	{
		$result = new Result();

		if (!$this->isModuleInstalled)
		{
			return $result->addError(new Error('Module iblock is not installed'));
		}

		$absenceTypeEnumId = null;
		$res = $this->fetchAbsenceTypes($iblockId);

		while ($absenceType = $res->Fetch())
		{
			if ($absenceType['XML_ID'] === $absence->getTypeXmlId())
			{
				$absenceTypeEnumId = $absenceType['ID'];
				break;
			}
		}

		if ($absenceTypeEnumId === null)
		{
			return $result->addError(new Error('Absence type "' . $absence->getTypeXmlId() . '" not found'));
		}

		$dbAbsenceProp = \CIBlockProperty::GetList([], ['CODE' => 'ABSENCE_TYPE', 'IBLOCK_ID' => $iblockId]);
		$absenceProp = $dbAbsenceProp->Fetch();

		$dbUserProp = \CIBlockProperty::GetList([], ['CODE' => 'USER', 'IBLOCK_ID' => $iblockId]);
		$userProp = $dbUserProp->Fetch();

		if (!$absenceProp || !$userProp)
		{
			return $result->addError(new Error('Required properties (ABSENCE_TYPE, USER) not found in iblock'));
		}

		$fields = [
			'IBLOCK_ID' => $iblockId,
			'NAME' => $absence->getDescription(),
			'ACTIVE_FROM' => $absence->getDateFrom()->toString(),
			'ACTIVE_TO' => $absence->getDateTo()->toString(),
			'PROPERTY_VALUES' => [
				$absenceProp['ID'] => $absenceTypeEnumId,
				$userProp['ID'] => $absence->getUserId(),
			],
		];

		$element = new \CIBlockElement();
		$elementId = $element->Add($fields);

		if (!$elementId)
		{
			return $result->addError(new Error('Failed to add absence record: ' . $element->LAST_ERROR));
		}

		$absence->setId((int)$elementId);

		return $result->setData([
			'absence' => $absence,
		]);
	}

	public function getAvailableTypes(int $iblockId): EntityCollection
	{
		if (!isset($this->availableTypesByIblockId[$iblockId]))
		{
			$defaultVacationTypes = $this->getDefaultTypes();
			$collection = new EntityCollection();

			if ($this->isModuleInstalled)
			{
				$res = $this->fetchAbsenceTypes($iblockId);

				while ($row = $res->GetNext())
				{
					$collection->add(
						new AbsenceType(
							(int)$row['ID'],
							$row['EXTERNAL_ID'],
							self::getTypeCaption($row['EXTERNAL_ID']),
							in_array((string)$row['EXTERNAL_ID'], $defaultVacationTypes, true),
						)
					);
				}
			}

			$this->availableTypesByIblockId[$iblockId] = $collection;
		}

		return $this->availableTypesByIblockId[$iblockId];
	}

	/** @return array<string, string> */
	private function getTypeXmlIdsByEnumId(int $iblockId): array
	{
		$xmlIdsByEnumId = [];
		foreach ($this->getAvailableTypes($iblockId) as $type)
		{
			if ($type instanceof AbsenceType)
			{
				$xmlIdsByEnumId[(string)$type->id] = $type->xmlId;
			}
		}

		return $xmlIdsByEnumId;
	}

	public static function getTypeCaption(string $type): string
	{
		try
		{
			return Loc::getMessage('INTRANET_ABSENCE_REPOSITORY_TYPE_' . $type) ?? '';
		}
		catch (\Throwable)
		{
			return '';
		}
	}

	protected function fetchAbsenceTypes(int $iblockId): \CIBlockPropertyEnumResult
	{
		return \CIBlockPropertyEnum::GetList(
			[
				'SORT' => 'ASC',
			],
			[
				'IBLOCK_ID' => $iblockId,
				'CODE' => 'ABSENCE_TYPE',
			]
		);
	}

	private function getDefaultTypes(): array
	{
		if (!is_array(self::$activeVacationTypes))
		{
			$defaultVacationTypes = [
				AbsenceTypeCode::Vacation->value,
				AbsenceTypeCode::SickLeave->value,
				AbsenceTypeCode::MaternityLeave->value,
				AbsenceTypeCode::UnpaidLeave->value,
			];

			$vacationTypesOption = Option::get('intranet', 'vacation_types', null);
			if ($vacationTypesOption)
			{
				$defaultVacationTypes = unserialize($vacationTypesOption, ["allowed_classes" => false]);
			}

			self::$activeVacationTypes = $defaultVacationTypes;
		}

		return self::$activeVacationTypes;
	}

	private static function dateContainsTime(mixed $date): bool
	{
		$dateString = $date instanceof Date ? $date->toString() : (string)$date;
		if ($dateString === '')
		{
			return false;
		}

		$matches = [];
		if (preg_match('/\b\d{1,2}:\d{2}(?::\d{2})?\b/', $dateString, $matches) !== 1)
		{
			return false;
		}

		[$hours, $minutes, $seconds] = array_pad(explode(':', $matches[0]), 3, '0');

		return (int)$hours !== 0 || (int)$minutes !== 0 || (int)$seconds !== 0;
	}
}
