<?php

namespace Bitrix\Crm\Activity\Entity\ConfigurableRestApp\Dto;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Caster\CollectionCaster;
use Bitrix\Crm\Dto\Caster\ObjectCaster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class FooterMenuDto extends Dto
{
	private const MAX_MENU_ITEMS_COUNT = 10;
	public const RESERVED_SECTION_CODES = ['system', 'about', 'base', 'extensions'];
	public const ERROR_RESERVED_SECTION_CODE = 'MENU_RESERVED_SECTION_CODE';
	public const ERROR_DUPLICATE_SECTION_CODE = 'MENU_DUPLICATE_SECTION_CODE';
	public const ERROR_MISSING_SECTION_CODE = 'MENU_MISSING_SECTION_CODE';
	public const ERROR_UNKNOWN_SECTION_CODE = 'MENU_UNKNOWN_SECTION_CODE';

	public ?bool $showPinItem = null;
	public ?bool $showPostponeItem = null;
	public ?bool $showDeleteItem = null;
	public ?array $items = null;
	public ?array $sections = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'items' => new CollectionCaster(new ObjectCaster(MenuItemDto::class)),
			'sections' => new CollectionCaster(new ObjectCaster(MenuSectionDto::class)),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		$validators = [
			new ObjectCollectionField($this, 'items', self::MAX_MENU_ITEMS_COUNT),
			new ObjectCollectionField($this, 'sections', 10),
		];

		$validators[] = new class ($this) extends Validator
		{
			public function validate(array $fields): Result
			{
				$result = new Result();

				$sections = $fields['sections'] ?? null;
				if (!is_array($sections) || empty($sections))
				{
					return $result;
				}

				$declaredCodes = [];
				foreach ($sections as $section)
				{
					if (is_array($section) && isset($section['code']))
					{
						$declaredCodes[] = (string)$section['code'];
					}
				}

				foreach ($declaredCodes as $code)
				{
					if (in_array($code, FooterMenuDto::RESERVED_SECTION_CODES, true))
					{
						$result->addError(new Error(
							"Section code '$code' is reserved and cannot be used by apps",
							FooterMenuDto::ERROR_RESERVED_SECTION_CODE
						));
					}
				}

				$seenCodes = [];
				foreach ($declaredCodes as $code)
				{
					if (isset($seenCodes[$code]))
					{
						$result->addError(new Error(
							"Section code '$code' is duplicated",
							FooterMenuDto::ERROR_DUPLICATE_SECTION_CODE
						));
					}

					$seenCodes[$code] = true;
				}

				$items = $fields['items'] ?? [];
				if (!is_array($items))
				{
					return $result;
				}

				foreach ($items as $index => $item)
				{
					if (!is_array($item))
					{
						continue;
					}

					$sectionCode = $item['sectionCode'] ?? null;
					if ($sectionCode === null || $sectionCode === '')
					{
						$result->addError(new Error(
							"Item at index $index must have sectionCode when sections are defined",
							FooterMenuDto::ERROR_MISSING_SECTION_CODE
						));
						continue;
					}

					if (!in_array($sectionCode, $declaredCodes, true))
					{
						$result->addError(new Error(
							"Item at index $index references unknown sectionCode '$sectionCode'",
							FooterMenuDto::ERROR_UNKNOWN_SECTION_CODE
						));
					}
				}

				return $result;
			}
		};

		return $validators;
	}
}
