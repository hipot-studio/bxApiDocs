<?php

namespace Bitrix\Crm\Import\Builder;

use Bitrix\Crm\EntityPreset;
use Bitrix\Crm\Import\Dto\UI\Dictionary;
use Bitrix\Crm\Import\Dto\UI\SettingsControl\SelectOption;
use Bitrix\Crm\Import\Enum\Delimiter;
use Bitrix\Crm\Import\Enum\DuplicateControl\DuplicateControlBehavior;
use Bitrix\Crm\Import\Enum\DuplicateControl\DuplicateControlTarget;
use Bitrix\Crm\Import\Enum\Encoding;
use Bitrix\Crm\Import\Enum\NameFormat;
use Bitrix\Crm\Restriction\RestrictionManager;
use CCrmStatus;

final class DictionaryBuilder
{
	private readonly EntityPreset $entityPreset;

	public function __construct()
	{
		$this->entityPreset = EntityPreset::getSingleInstance();
	}

	public function build(int $entityTypeId): Dictionary
	{
		return (new Dictionary())
			->setNameFormats($this->getNameFormats())
			->setRequisitePresets($this->getRequisitePresets())
			->setDelimiters($this->getDelimiters())
			->setEncodings($this->getEncodings())
			->setContactTypes($this->getContactTypes())
			->setSources($this->getSources())
			->setDuplicateControlBehaviors($this->getDuplicateControlBehaviors())
			->setDuplicateControlTargets($this->getDuplicateControlTargets($entityTypeId))
			->setDuplicateControlPermitted($this->getIsDuplicateControlPermitted())
		;
	}

	private function getNameFormats(): array
	{
		return SelectOption::fromEnum(NameFormat::class);
	}

	private function getRequisitePresets(): array
	{
		$requisitePresetsResult = $this->entityPreset
			?->getList([
				'select' => ['ID', 'NAME'],
				'filter' => [
					'=ENTITY_TYPE_ID' => EntityPreset::Requisite,
					'=ACTIVE' => 'Y',
				],
				'order' => [
					'SORT' => 'ASC',
					'ID' => 'ASC',
				],
			])
		;

		$requisitePresets = [];
		foreach ($requisitePresetsResult->fetchCollection()?->getAll() ?? [] as $preset)
		{
			$title = EntityPreset::formatName($preset->getId(), $preset->getName());
			$requisitePresets[] = new SelectOption($preset->getId(), $title);
		}

		return $requisitePresets;
	}

	private function getDelimiters(): array
	{
		return SelectOption::fromEnum(Delimiter::class);
	}

	private function getEncodings(): array
	{
		return SelectOption::fromEnum(Encoding::class);
	}

	private function getContactTypes(): array
	{
		return SelectOption::fromStatusList(CCrmStatus::GetStatusList('CONTACT_TYPE'));
	}

	private function getSources(): array
	{
		return SelectOption::fromStatusList(CCrmStatus::GetStatusList('SOURCE'));
	}

	private function getDuplicateControlBehaviors(): array
	{
		return SelectOption::fromEnum(DuplicateControlBehavior::class);
	}

	private function getDuplicateControlTargets(int $entityTypeId): array
	{
		return SelectOption::fromEnumCases(
			DuplicateControlTarget::getCasesForEntity($entityTypeId)
		);
	}

	private function getIsDuplicateControlPermitted(): bool
	{
		return RestrictionManager::isDuplicateControlPermitted();
	}
}
