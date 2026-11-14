<?php

namespace Bitrix\Disk\Ui;

use Bitrix\Main\Engine\CurrentUser;

/**
 * main.ui.filter builds the preset menu from the list saved in user options, not from FILTER_PRESETS,
 * so presets released later stay invisible for everyone who has already saved filter settings.
 * The saved list is patched in place: only the missing presets are inserted, everything the user has
 * changed keeps its data and its place.
 */
final class FilterPresetSynchronizer
{
	private const OPTIONS_CATEGORY = 'main.ui.filter';
	private const COMMON_OPTIONS_CATEGORY = 'main.ui.filter.common';
	private const DEFAULT_PRESET_ID = 'default_filter';
	private const DEFAULT_SORT = 1000;

	public function addMissingPresets(string $filterId, array $releasedPresets): void
	{
		if (empty($releasedPresets))
		{
			return;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return;
		}

		$storedOptions = $this->readEffectiveOptions($filterId, $userId);
		if ($storedOptions === null)
		{
			// nothing saved yet: Options builds the menu from the released presets on its own
			return;
		}

		$deletedPresets = is_array($storedOptions['deleted_presets'] ?? null) ? $storedOptions['deleted_presets'] : [];
		$missingPresets = array_diff_key($releasedPresets, $storedOptions['filters'], $deletedPresets);
		$hasStaleUpdateFlag = !empty($storedOptions['update_default_presets']);
		if (empty($missingPresets) && !$hasStaleUpdateFlag)
		{
			return;
		}

		if (!empty($missingPresets))
		{
			$storedOptions['filters'] = $this->insertMissingPresets(
				$storedOptions['filters'],
				$releasedPresets,
				$missingPresets,
			);
		}

		// the flag makes Options overwrite every released preset with its shipped copy
		unset($storedOptions['update_default_presets']);

		\CUserOptions::setOption(self::OPTIONS_CATEGORY, $filterId, $storedOptions, false, $userId);
	}

	/**
	 * Repeats {@see \Bitrix\Main\UI\Filter\Options::fetchOptions()}: for a user without own settings
	 * the common ones are the source. Returns null when there is nothing to patch.
	 */
	private function readEffectiveOptions(string $filterId, int $userId): ?array
	{
		$options = \CUserOptions::getOption(self::OPTIONS_CATEGORY, $filterId, [], $userId);
		if (empty($options))
		{
			$options = \CUserOptions::getOption(self::COMMON_OPTIONS_CATEGORY, $filterId, [], 0);
		}

		if (!is_array($options) || !isset($options['filters']) || !is_array($options['filters']))
		{
			return null;
		}

		return $options;
	}

	/**
	 * The missing presets go in one group right after the last released preset that is still saved,
	 * so the presets the user already has keep their order and places whatever the released order is.
	 */
	private function insertMissingPresets(array $storedPresets, array $releasedPresets, array $missingPresets): array
	{
		$orderedIds = $this->orderPresetIdsBySort($storedPresets);
		$position = $this->findInsertPosition($orderedIds, array_diff_key($releasedPresets, $missingPresets));
		array_splice($orderedIds, $position, 0, array_keys($missingPresets));

		$presets = [];
		$sort = 0;
		foreach ($orderedIds as $presetId)
		{
			$presets[$presetId] = $storedPresets[$presetId] ?? $missingPresets[$presetId];
			$presets[$presetId]['sort'] = $sort;
			$sort++;
		}

		return $presets;
	}

	/** The menu order of main.ui.filter: by sort ascending, a preset without one goes to the end. */
	private function orderPresetIdsBySort(array $presets): array
	{
		$sortByPresetId = [];
		foreach ($presets as $presetId => $preset)
		{
			$sort = $preset['sort'] ?? null;
			$sortByPresetId[$presetId] = is_numeric($sort) ? (int)$sort : self::DEFAULT_SORT;
		}

		asort($sortByPresetId);

		return array_keys($sortByPresetId);
	}

	private function findInsertPosition(array $orderedIds, array $knownReleasedPresets): int
	{
		$position = 0;
		foreach ($orderedIds as $index => $presetId)
		{
			if (isset($knownReleasedPresets[$presetId]))
			{
				$position = $index + 1;
			}
		}

		if ($position === 0 && ($orderedIds[0] ?? null) === self::DEFAULT_PRESET_ID)
		{
			$position = 1;
		}

		return $position;
	}
}
