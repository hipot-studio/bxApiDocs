<?php

namespace Bitrix\Bizproc\Integration\AiAssistant;

use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCategory;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOption;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOptionCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingType;

/**
 * Merges a structural base schema (built from the activity's properties map) with curated overrides
 * from a manual `.ai.php` description. The map is the structural source of truth; the manual
 * description enriches it field-by-field with curated descriptions, option labels, and special types.
 *
 * Guardrails:
 *   G1  - empty dynamic select in map + scalar type from .ai.php -> take .ai.php type (and options).
 *   G2  - option set identity comes from the map, but each option's label is enriched from .ai.php.
 *   G-opt - a map-only field whose type is an empty dynamic select is silently dropped (useless noise).
 */
class ActivityAiDescriptionMerger
{
	/**
	 * Base types that represent dynamic select fields (options loaded at runtime).
	 * Matches FieldType::SELECT and FieldType::INTERNALSELECT.
	 */
	private const DYNAMIC_SELECT_BASE_TYPES = ['select', 'internalselect'];

	/**
	 * Merges two setting collections.
	 *
	 * Fields present in both $base and $override are structurally taken from $base
	 * and qualitatively enriched from $override (description, type glossary, option labels).
	 * Fields only in $base are kept as-is (unless G-opt drops them).
	 * Fields only in $override are appended as-is (the map could not express them).
	 */
	public function merge(SettingCollection $base, SettingCollection $override): SettingCollection
	{
		$dropped = [];

		return $this->mergeCollecting($base, $override, $dropped);
	}

	/**
	 * Like {@see merge()} but also returns the names of map-only fields dropped by G-opt.
	 *
	 * The caller (gate hybrid path) includes these names in skippedSettings so that the
	 * AgentSettingNameValidator accepts them on round-trip and existing template values are
	 * preserved rather than rejected.
	 *
	 * @return array{0: SettingCollection, 1: list<string>} [$merged, $gOptDroppedNames]
	 */
	public function mergeWithDropped(SettingCollection $base, SettingCollection $override): array
	{
		$dropped = [];
		$merged = $this->mergeCollecting($base, $override, $dropped);

		return [$merged, $dropped];
	}

	/**
	 * Internal merge implementation. Populates $dropped with names of fields silently removed by G-opt.
	 *
	 * @param list<string> $dropped Accumulator for G-opt dropped field names (passed by reference).
	 */
	private function mergeCollecting(
		SettingCollection $base,
		SettingCollection $override,
		array &$dropped,
	): SettingCollection
	{
		$out = new SettingCollection();

		foreach ($base as $m)
		{
			$a = $override->findFirstByName($m->name);
			if ($a !== null)
			{
				$out->add($this->mergeSetting($m, $a));
			}
			elseif (!$this->isEmptyDynamicSelect($m->type, $m->options))
			{
				// G-opt: silently drop a map-only field that is an empty dynamic select -
				// it carries no useful option set and cannot be acted on by the AI agent.
				$out->add($m);
			}
			else
			{
				$dropped[] = $m->name;
			}
		}

		foreach ($override as $a)
		{
			if ($base->findFirstByName($a->name) === null)
			{
				// Field only in .ai.php - the map could not express it -> add as-is.
				$out->add($a);
			}
		}

		return $out;
	}

	/**
	 * Merges one map Setting with its .ai.php counterpart into a new Setting.
	 *
	 * Structural attributes (required, multiple, defaultValue, allowSelection) always come from the map.
	 * Quality attributes (description, special type, curated option labels) come from .ai.php.
	 * Declared attributes (lifecycle category, map key mode, unique child key, numeric bounds) are the map's
	 * blind spot - it derives none of them - so a value stated by .ai.php wins over the default the map leaves
	 * behind.
	 */
	private function mergeSetting(Setting $map, Setting $ai): Setting
	{
		return new Setting(
			name: $map->name,
			description: $ai->description !== '' ? $ai->description : $map->description,
			type: $this->resolveType($map->type, $map->options, $ai->type),
			required: $map->required,
			multiple: $map->multiple,
			options: $this->mergeOptions($map->options, $ai->options),
			children: $this->mergeChildren($map->children, $ai->children),
			defaultValue: $map->defaultValue ?? $ai->defaultValue,
			allowSelection: $map->allowSelection,
			category: $ai->category === SettingCategory::EditableTyped ? $map->category : $ai->category,
			keyMode: $ai->keyMode ?? $map->keyMode,
			uniqueChildKey: $ai->uniqueChildKey ?? $map->uniqueChildKey,
			minimum: $ai->minimum ?? $map->minimum,
			maximum: $ai->maximum ?? $map->maximum,
		);
	}

	/**
	 * Resolves the effective type for a merged field.
	 *
	 * Priority rules:
	 *   1. Special type from .ai.php (SettingType) - always wins; it carries a glossary entry.
	 *   2. Special type from the map (SettingType) - preserve if .ai.php gave a plain string.
	 *   3. G1: if the map type is an empty dynamic select and .ai.php gives a non-empty scalar -> use .ai.php.
	 *   4. Otherwise: keep the map type.
	 */
	private function resolveType(
		string|SettingType $mapType,
		?SettingOptionCollection $mapOptions,
		string|SettingType $aiType,
	): string|SettingType
	{
		if ($aiType instanceof SettingType)
		{
			// .ai.php curated a special type (with glossary) - always wins.
			return $aiType;
		}

		if ($mapType instanceof SettingType)
		{
			// Map declared a special type - preserve it when .ai.php gave only a plain string.
			return $mapType;
		}

		// G1: empty dynamic select in map + non-empty scalar type from .ai.php -> take .ai.php.
		if ($this->isEmptyDynamicSelect($mapType, $mapOptions) && is_string($aiType) && $aiType !== '')
		{
			return $aiType;
		}

		return $mapType;
	}

	/**
	 * Merges option collections (G2).
	 *
	 * If the map has no options, .ai.php options are used directly.
	 * Otherwise, the map's set of option ids is kept but each label is enriched
	 * from the matching .ai.php option (curated label wins when ids match).
	 */
	private function mergeOptions(
		?SettingOptionCollection $mapOptions,
		?SettingOptionCollection $aiOptions,
	): ?SettingOptionCollection
	{
		if ($mapOptions === null)
		{
			// G2: no static options in map -> use whatever .ai.php provided.
			return $aiOptions;
		}

		$out = new SettingOptionCollection();
		foreach ($mapOptions as $mapOpt)
		{
			$aiOpt = $this->findOptionById($mapOpt->id, $aiOptions);
			$out->add(
				$aiOpt !== null
					? new SettingOption(
						id: $mapOpt->id,
						name: $aiOpt->name !== '' ? $aiOpt->name : $mapOpt->name,
						description: $aiOpt->description,
					)
					: $mapOpt
			);
		}

		return $out;
	}

	/**
	 * Merges children collections recursively.
	 * When one side is null the other is used directly.
	 */
	private function mergeChildren(
		?SettingCollection $mapChildren,
		?SettingCollection $aiChildren,
	): ?SettingCollection
	{
		if ($mapChildren === null)
		{
			return $aiChildren;
		}

		if ($aiChildren === null)
		{
			return $mapChildren;
		}

		return $this->merge($mapChildren, $aiChildren);
	}

	/**
	 * Returns true when $type is a plain-string dynamic select type with no static options.
	 * Used by G1 (type replacement) and G-opt (map-only field suppression).
	 */
	private function isEmptyDynamicSelect(string|SettingType $type, ?SettingOptionCollection $options): bool
	{
		return is_string($type)
			&& in_array(mb_strtolower($type), self::DYNAMIC_SELECT_BASE_TYPES, true)
			&& ($options === null || $options->count() === 0);
	}

	private function findOptionById(string $id, ?SettingOptionCollection $options): ?SettingOption
	{
		if ($options === null)
		{
			return null;
		}

		foreach ($options as $option)
		{
			if ($option->id === $id)
			{
				return $option;
			}
		}

		return null;
	}
}
