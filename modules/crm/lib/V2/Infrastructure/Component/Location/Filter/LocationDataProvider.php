<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Location\Filter;

use Bitrix\Crm\V2\Internal\Service\Location\LocationListService;
use Bitrix\Main\Filter\EntityDataProvider;
use Bitrix\Main\Filter\Settings;
use Bitrix\Main\UI\Filter\NumberType;
use Bitrix\Main\UI\Filter\Type;

final class LocationDataProvider extends EntityDataProvider
{
	private const STANDARD_FIELD_IDS = [
		'ID',
		'CODE',
		'SORT',
		'PARENT_ID',
		'TYPE_ID',
		'LATITUDE',
		'LONGITUDE',
	];

	private readonly Settings $settings;
	private readonly string $nameFieldId;

	public function __construct(
		string $id,
		string $languageId,
		private readonly array $fieldDescriptions,
		private readonly array $typeItems = [],
	)
	{
		$this->settings = new Settings(['ID' => $id]);
		$this->nameFieldId = 'NAME_' . mb_strtoupper($languageId);
	}

	public function getSettings(): Settings
	{
		return $this->settings;
	}

	public function prepareFields(): array
	{
		$fields = [];
		foreach ($this->fieldDescriptions as $id => $description)
		{
			if (
				!in_array($id, self::STANDARD_FIELD_IDS, true)
				&& !$this->isLocalizedNameField((string)$id)
			)
			{
				continue;
			}

			$fields[] = $this->createField(
				(string)$id,
				[
					'type' => $id === 'TYPE_ID'
						? 'list'
						: $this->resolveType((string)($description['data_type'] ?? 'string')),
					'name' => (string)($description['title'] ?? $id),
					'default' => (bool)($description['DEFAULT'] ?? false),
					'partial' => $id === 'TYPE_ID',
					'exclude' => $id === 'PARENT_ID'
						? [NumberType::RANGE, NumberType::MORE, NumberType::LESS]
						: null,
				],
			);
		}

		return $fields;
	}

	public function prepareFieldData($fieldID): ?array
	{
		if ($fieldID !== 'TYPE_ID')
		{
			return null;
		}

		return [
			'params' => ['multiple' => 'N'],
			'items' => $this->typeItems,
		];
	}

	public function prepareListFilterParam(array &$filter, $fieldID): void
	{
	}

	public function prepareFilterValue(array $rawFilterValue): array
	{
		$filterValue = parent::prepareFilterValue($rawFilterValue);
		if (array_key_exists('=PARENT_ID', $filterValue))
		{
			$filterValue['PARENT_ID'] = $filterValue['=PARENT_ID'];
			unset($filterValue['=PARENT_ID']);
		}

		$find = trim((string)($filterValue['FIND'] ?? ''));
		if ($find !== '')
		{
			$filterValue[LocationListService::QUICK_SEARCH_FILTER_KEY] = $find;
		}

		return $filterValue;
	}

	private function resolveType(string $dataType): string
	{
		return match ($dataType)
		{
			'integer', 'float' => mb_strtolower(Type::NUMBER),
			default => mb_strtolower(Type::STRING),
		};
	}

	private function isLocalizedNameField(string $fieldId): bool
	{
		return $fieldId === $this->nameFieldId
			|| str_starts_with($fieldId, 'NAME_')
			|| str_starts_with($fieldId, 'SHORT_NAME_')
		;
	}
}
