<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

use Bitrix\Main\UI\Filter\DateType;
use Bitrix\Main\UI\Filter\NumberType;

/**
 * Normalizes a raw web filter descriptor (any Bitrix\Main\Filter provider) into the canonical
 * mobile FieldDescriptor of CONTRACT-DTO-01.
 *
 * Mobile does not invent fields: it renders only what the web provider exposes. A field the
 * mobile cannot edit (e.g. a selector for an entity with no mobile picker) is marked locked
 * instead of being dropped.
 *
 * The class is shared by every consumer of the unified filter, so it knows no module specifics.
 * A module maps its own selector entities in getEntityMap() and, if its provider uses a control
 * the base does not know, widens mapType() or resolveEntity().
 */
class FieldDescriptorNormalizer
{
	/**
	 * The pair a boolean field travels in when its provider counts the flag in numbers, as the web
	 * checkbox control names it (`FieldAdapter::CHECKBOX`): `1`/`0` instead of the default `Y`/`N`.
	 */
	public const VALUE_TYPE_NUMERIC = 'numeric';

	/** @var string[]|null */
	private ?array $allDatePresets = null;

	/** @var array<string, string>|null the map of the module, asked for once per request */
	private ?array $entityMap = null;

	/**
	 * @param array<int, array> $rawFields
	 * @return array<int, array>
	 */
	public function normalizeList(array $rawFields): array
	{
		return array_map([$this, 'normalize'], array_values($rawFields));
	}

	public function normalize(array $raw): array
	{
		$type = $this->mapType((string)($raw['type'] ?? ''));

		$entity = null;
		$locked = false;
		$dialogItems = null;
		if ($type === FieldType::EntitySelector)
		{
			$entity = $this->resolveEntity($raw);

			// a dialog that carries the values themselves offers a closed set and needs no picker at
			// all: `WEBFORM_ID` of the CRM lists names every form of the portal right in the descriptor
			$dialogItems = $entity === null ? $this->getDialogItems($raw) : null;
			if ($dialogItems !== null)
			{
				$type = FieldType::ListItems;
			}

			$locked = ($entity === null && $dialogItems === null);
		}

		$descriptor = [
			'id' => (string)($raw['id'] ?? ''),
			'name' => (string)($raw['name'] ?? ''),
			'type' => $type->value,
			'multiple' => $this->isMultiple($raw),
			'default' => (bool)($raw['default'] ?? false),
			'locked' => $locked,
		];

		// the web provider already groups its fields (Main\Filter\Field::toArray()), so the mobile
		// form groups them the same way instead of inventing a division of its own
		$sectionId = (string)($raw['sectionId'] ?? '');
		if ($sectionId !== '')
		{
			$descriptor['sectionId'] = $sectionId;
		}

		if ($type === FieldType::ListItems)
		{
			$descriptor['items'] = $dialogItems ?? $this->mapItems($raw['items'] ?? []);
		}

		if ($entity !== null)
		{
			$descriptor['entity'] = $entity;

			$entityOptions = $this->getEntityOptions($raw, $entity);
			if ($entityOptions !== [])
			{
				$descriptor['entityOptions'] = $entityOptions;
			}
		}

		$additionalFilter = $this->getAdditionalFilter($raw);
		if ($additionalFilter !== [])
		{
			$descriptor['additionalFilter'] = $additionalFilter;
		}

		if ($type === FieldType::Boolean && ($raw['valueType'] ?? null) === self::VALUE_TYPE_NUMERIC)
		{
			// A boolean user field of CRM keeps the flag in numbers (`CCrmUserType::PrepareFilterFields`),
			// and the web control filters it by `1`/`0`. Without the pair the client writes `Y`/`N`: the
			// condition finds nothing and the value the web stored is dropped on the next apply.
			$descriptor['valueType'] = self::VALUE_TYPE_NUMERIC;
		}

		if ($type === FieldType::Number)
		{
			// the web narrows the comparisons of a number the same way it narrows the periods of a date,
			// and the mobile control has no business offering more than the web does
			$excludedOperators = $this->getExcludedNumberOperators($raw);
			if ($excludedOperators !== [])
			{
				$descriptor['excludedNumberOperators'] = $excludedOperators;
			}
		}

		if ($type === FieldType::Date)
		{
			// the periods are worded once per answer (FilterDictionary), so a field only says which
			// of them its provider takes away - usually none
			$excluded = $this->getExcludedDatePresets($raw);
			if ($excluded !== [])
			{
				$descriptor['excludedDatePresets'] = $excluded;
			}

			// the web date control reads both flags from the same raw descriptor (FieldAdapter::DATE),
			// so dropping them would render a datetime picker where the web shows a plain date
			$descriptor['withTime'] = (bool)($raw['time'] ?? false);
			$descriptor['allowYearsSwitcher'] = (bool)($raw['allow_years_switcher'] ?? false);
		}

		return $descriptor;
	}

	/**
	 * The control of the web filter to the control the mobile form draws. A name the web has and this
	 * map has not is a text field: the raw descriptor of every web control carries a name and a value,
	 * and a text field is the one control that can show them both.
	 */
	protected function mapType(string $rawType): FieldType
	{
		return match ($rawType)
		{
			'number' => FieldType::Number,
			'date' => FieldType::Date,
			'list' => FieldType::ListItems,
			'checkbox' => FieldType::Boolean,
			'entity_selector' => FieldType::EntitySelector,
			'fulltext' => FieldType::Fulltext,
			default => FieldType::String,
		};
	}

	/**
	 * Web entity id of the selector dialog to the canonical mobile entity of CONTRACT-DTO-01.
	 * Empty in the base: without a mapping every selector is locked, which is the honest
	 * answer for a module that has not declared its pickers.
	 *
	 * @return array<string, string>
	 */
	protected function getEntityMap(): array
	{
		return [];
	}

	/**
	 * A selector dialog may offer several entities at once (users and departments, for one),
	 * while a mobile picker serves a single entity. The first entity the module knows how to
	 * open wins; an unknown dialog leaves the field locked.
	 */
	protected function resolveEntity(array $raw): ?string
	{
		// every selector field of the dictionary asks for the same map, so the module is asked once
		$map = $this->entityMap ??= $this->getEntityMap();

		foreach ($raw['params']['dialogOptions']['entities'] ?? [] as $entity)
		{
			$webEntity = $entity['id'] ?? null;
			if (is_string($webEntity) && isset($map[$webEntity]))
			{
				return $map[$webEntity];
			}
		}

		return null;
	}

	/**
	 * What the dialog of the web narrows the entity to: my companies alone for a my-company requisites
	 * field, the products of one catalogue, the employees of the portal without its extranet. The options
	 * are read by the search provider of the entity - the same provider the mobile picker asks - so the
	 * picker only offers what the web dialog would once the field carries them.
	 *
	 * The options of the entity the picker opens and of no other: a user dialog of CRM offers the fired
	 * users and the departments next to the employees, and their options are about lists the mobile
	 * picker never opens.
	 *
	 * @return array<string, mixed> empty when the dialog restricts nothing
	 */
	protected function getEntityOptions(array $raw, string $entity): array
	{
		$map = $this->entityMap ??= $this->getEntityMap();

		foreach ($raw['params']['dialogOptions']['entities'] ?? [] as $dialogEntity)
		{
			$webEntity = ($dialogEntity['id'] ?? null);
			if (!is_string($webEntity) || ($map[$webEntity] ?? null) !== $entity)
			{
				continue;
			}

			$options = ($dialogEntity['options'] ?? null);

			return is_array($options) ? $options : [];
		}

		return [];
	}

	/**
	 * Both spellings the web control accepts (FieldAdapter::normalize): a provider writing a boolean
	 * gets a multiple field in the web, so it has to get one here too.
	 */
	protected function isMultiple(array $raw): bool
	{
		$multiple = ($raw['params']['multiple'] ?? false);

		return $multiple === 'Y' || $multiple === true;
	}

	/**
	 * Values a selector dialog carries itself, as a list field takes them. The web keeps them under
	 * `dialogOptions.items` - the forms of `WEBFORM_ID`, for one - and a set given in full is a list and
	 * not a search over an entity.
	 *
	 * @return array<int, array{id: string, value: string}>|null null when the dialog carries no values
	 */
	protected function getDialogItems(array $raw): ?array
	{
		$items = ($raw['params']['dialogOptions']['items'] ?? null);
		if (!is_array($items) || $items === [])
		{
			return null;
		}

		$result = [];
		foreach ($items as $item)
		{
			$id = is_array($item) ? (string)($item['id'] ?? '') : '';
			$title = is_array($item) ? trim((string)($item['title'] ?? '')) : '';

			if ($id !== '' && $title !== '')
			{
				$result[] = ['id' => $id, 'value' => $title];
			}
		}

		return $result === [] ? null : $result;
	}

	/**
	 * Comparisons the provider of a number field takes away, as `Main\UI\Filter\NumberType` names them.
	 * Only the ones the client knows are named: anything else excludes nothing there.
	 *
	 * @return string[]
	 */
	protected function getExcludedNumberOperators(array $raw): array
	{
		$exclude = ($raw['exclude'] ?? null);
		if (!is_array($exclude) || $exclude === [])
		{
			return [];
		}

		return array_values(
			array_intersect(array_values(NumberType::getList()), array_filter($exclude, 'is_string')),
		);
	}

	/**
	 * Conditions on the presence of a value the field allows - the "---" menu of the web filter.
	 * A provider declares them per field, so a field that declares none offers none on mobile
	 * either. Unknown conditions are dropped: the mobile filter can only send what it can word.
	 *
	 * @return string[]
	 */
	protected function getAdditionalFilter(array $raw): array
	{
		$allowed = $raw['additionalFilter'] ?? null;
		if (!is_array($allowed))
		{
			return [];
		}

		return array_values(
			array_intersect(AdditionalFilterType::names(), array_filter($allowed, 'is_string')),
		);
	}

	/**
	 * @return array<int, array{id: string, value: string}>
	 */
	protected function mapItems(array $items): array
	{
		$result = [];
		foreach ($items as $id => $value)
		{
			$id = (string)$id;
			// the web select takes an item as a scalar or as an array with the name inside it
			// (FieldAdapter::LIST), and the second spelling would otherwise read as "Array"
			$value = trim((string)(is_array($value) ? ($value['NAME'] ?? '') : $value));

			// The web select carries an option for "not chosen": sometimes unnamed, sometimes named
			// "(all)", always keyed by an empty id. The mobile list has a row of its own that clears
			// the field, so such an option comes out either as an empty row or as a second any-value row.
			if ($id === '' || $value === '')
			{
				continue;
			}

			$result[] = [
				'id' => $id,
				'value' => $value,
			];
		}

		return $result;
	}

	/**
	 * Periods the provider of the field takes away from the vocabulary - the `exclude` of the web date
	 * control. Only the periods the vocabulary itself holds are named: anything else excludes nothing
	 * on the client and would only take room in the answer.
	 *
	 * @return string[] in the order of the vocabulary, whatever order the provider listed them in
	 */
	protected function getExcludedDatePresets(array $raw): array
	{
		$exclude = $raw['exclude'] ?? null;
		if (!is_array($exclude) || $exclude === [])
		{
			return [];
		}

		return array_values(
			array_intersect($this->getAllDatePresets(), array_filter($exclude, 'is_string')),
		);
	}

	/**
	 * NONE is the web "not set" placeholder, not a period: the mobile form has no control for it.
	 *
	 * @return string[]
	 */
	private function getAllDatePresets(): array
	{
		$this->allDatePresets ??= array_values(array_diff(DateType::getList(), [DateType::NONE]));

		return $this->allDatePresets;
	}
}
