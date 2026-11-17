<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Filter\Field;

use Bitrix\Main\UI\Filter\Options;

/**
 * Everything the tasks.filter-field-mode extension needs to mount: which fields offer a mode, the satellite values
 * of the applied filter and the satellite values of every saved preset.
 *
 * The field config decides what is offered: fields carry a mode declaration only where the modes are available at
 * all, so an empty config means no control is built anywhere.
 *
 * The satellite values are a separate matter and outlive the toggle. Every apply re-saves the current preset from
 * what the page sends, and Options::setFilterSettings() replaces the stored fields with it; a satellite has no DOM
 * field of its own, so it returns only when the page was handed it here. Handed nothing, the first apply after the
 * toggle goes off would silently reset the modes and the operator the presets were saved with. So the values are
 * reported even with an empty config, and the extension mounts for them alone - hooks, no controls.
 *
 * Nothing to offer and nothing to carry means nothing to mount: then the assets stay off the page.
 */
final class ModeMountData
{
	/**
	 * @param array<string, array> $fields Mode declaration per filter field id.
	 * @param array<string, string> $values Satellite values of the applied filter.
	 * @param array<string, array<string, string>> $presets Satellite values per preset id.
	 */
	private function __construct(
		private readonly array $fields,
		private readonly array $values,
		private readonly array $presets,
	)
	{
	}

	/**
	 * @param array<string, array> $filterFields Filter field definitions as Helper\Filter builds them.
	 * @param list<string> $satelliteFieldIds Fields that can carry a satellite here (Helper\Filter::getSatelliteFieldIds).
	 */
	public static function forFilter(?string $filterId, array $filterFields, array $satelliteFieldIds = []): self
	{
		$fields = self::collectModeFields($filterFields);
		// A declared field owns its satellite keys. Where nothing is declared the ids come from the caller instead:
		// the values saved while the modes were available still have to reach the page.
		$fieldIds = $fields === [] ? $satelliteFieldIds : array_keys($fields);

		// Options throws on an empty id, and with no field able to carry a satellite there is nothing to read anyway.
		if ($fieldIds === [] || $filterId === null || $filterId === '')
		{
			return new self([], [], []);
		}

		$options = new Options($filterId);

		// The field definitions are mandatory here: without them getFilter() answers a request carrying
		// apply_filter by overwriting the applied filter with the query alone and saving that.
		$applied = SatelliteValues::fromAppliedFilter($options->getFilter($filterFields), $fieldIds);

		return new self($fields, $applied->toArray(), self::collectPresetValues($options, $fieldIds));
	}

	/**
	 * Nothing for the extension to do: no field offers a mode and no saved satellite has to be carried back.
	 */
	public function isEmpty(): bool
	{
		return $this->fields === [] && $this->values === [] && $this->presets === [];
	}

	public function toArray(): array
	{
		return [
			'fields' => $this->fields,
			'values' => $this->values,
			'presets' => $this->presets,
		];
	}

	/**
	 * @param array<string, array> $filterFields
	 * @return array<string, array>
	 */
	private static function collectModeFields(array $filterFields): array
	{
		$fields = [];
		foreach ($filterFields as $field)
		{
			$fieldId = is_array($field) ? ($field['id'] ?? null) : null;
			if (!is_string($fieldId))
			{
				continue;
			}

			$declaration = ModeDeclaration::fromFieldDefinition($field);
			if ($declaration !== null)
			{
				$fields[$fieldId] = $declaration->toArray();
			}
		}

		return $fields;
	}

	/**
	 * Switching a preset rebuilds the field list from the preset descriptors, and the mode has no field of its own
	 * to be rebuilt from. Without this map the extension cannot tell which mode the preset it switches to was saved
	 * with, and the apply that immediately follows would persist "equal" over it.
	 *
	 * @param list<string> $fieldIds
	 * @return array<string, array<string, string>>
	 */
	private static function collectPresetValues(Options $options, array $fieldIds): array
	{
		$presets = [];
		foreach (($options->getPresets() ?? []) as $presetId => $settings)
		{
			if (!is_string($presetId))
			{
				continue;
			}

			$settings = is_array($settings) ? $settings : [];
			$settings['additional'] = array_merge(
				is_array($settings['additional'] ?? null) ? $settings['additional'] : [],
				$options->getAdditionalPresetFields($presetId),
			);

			$satellites = SatelliteValues::fromPresetSettings($settings, $fieldIds);
			if (!$satellites->isEmpty())
			{
				$presets[$presetId] = $satellites->toArray();
			}
		}

		return $presets;
	}
}
