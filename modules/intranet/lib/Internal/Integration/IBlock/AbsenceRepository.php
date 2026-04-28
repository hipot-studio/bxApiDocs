<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\IBlock;

use Bitrix\Intranet\Internal\Entity\AbsenceType;
use Bitrix\Intranet\Internal\Entity\User\Absence;
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

		$absenceTypeEnumXmlId = [];
		$typesRes = $this->fetchAbsenceTypes($iblockId);

		while ($enum = $typesRes->Fetch())
		{
			$absenceTypeEnumXmlId[$enum['ID']] = $enum['XML_ID'];
		}

		$res = \CIBlockElement::GetList(
			[],
			[
				'IBLOCK_ID' => $iblockId,
				'ACTIVE' => 'Y',
				'PROPERTY_USER' => $userId,
				[
					'LOGIC' => 'AND',
					'>=ACTIVE_FROM' => $dateFrom->toString(),
					'<=ACTIVE_TO' => $dateTo->toString(),
				]
			],
			false,
			false,
			['ID', 'NAME', 'ACTIVE_FROM', 'ACTIVE_TO', 'PROPERTY_ABSENCE_TYPE'],
		);

		while ($absence = $res->Fetch())
		{
			$xmlId = $absenceTypeEnumXmlId[$absence['PROPERTY_ABSENCE_TYPE_ENUM_ID']] ?? '';

			$collection->add(
				new Absence(
					$userId,
					new DateTime($absence['ACTIVE_FROM']),
					new DateTime($absence['ACTIVE_TO']),
					$absence['NAME'],
					$xmlId,
					self::getTypeCaption($xmlId),
					(int)$absence['ID'],
				)
			);
		}

		return $collection;
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

	public static function getTypeCaption(string $type): string
	{
		return Loc::getMessage('INTRANET_ABSENCE_REPOSITORY_TYPE_' . $type) ?? '';
	}

	private function fetchAbsenceTypes(int $iblockId): \CIBlockPropertyEnumResult
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
				'VACATION',
				'LEAVESICK',
				'LEAVEMATERINITY',
				'LEAVEUNPAYED',
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
}
