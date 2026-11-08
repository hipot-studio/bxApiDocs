<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

use Bitrix\Main\UI\Filter\Options;

/**
 * The filter the client applied, remembered as the current one of the list.
 *
 * The storage of the filter is shared with the web, and the screen reads it back: a request naming no
 * preset is answered by the pointer of that storage (FilterRequest::usesStoredFilter), which is how a
 * screen shows its list before the dictionary of the filter arrives. So whoever applies a filter has
 * to move that pointer, or the mobile client only ever follows the web and never the other way round
 * - leaving a list and coming back to it brings back the filter the user has already replaced, while
 * the drawer goes on showing the one they chose.
 *
 * `Options::setFilterSettings()` moves the pointer of its own accord only for a request carrying
 * `apply_filter` or `clear_filter` - the protocol of the web form, which a mobile list action does not
 * speak - hence the explicit answer here. The same call saves a filter from the drawer
 * (SavedFilterStorage::save).
 *
 * One class for every mobile list: the lists of tasks and projects apply a filter through `remember()`,
 * which composes the settings out of the values of the form, and the lists of CRM through
 * `rememberSettings()`, which is handed the settings its own strategy has already composed
 * (CrmMobile\Kanban\ControllerStrategy\Base::setFilterPreset).
 */
final class AppliedFilterPointer
{
	public function __construct(private readonly Options $storage)
	{
	}

	/**
	 * `Options::save()` writes the whole storage of the list back - every preset of the user with it -
	 * while the options a list was built from are a snapshot taken before its items were read. A filter
	 * saved from the drawer in between is in the storage and not in that snapshot, so saving the
	 * snapshot would delete it. The pointer is therefore moved on a storage read at the moment of the
	 * write.
	 */
	public static function forList(Options $listOptions): self
	{
		return new self(new Options($listOptions->getId(), $listOptions->getDefaultPresets()));
	}

	/**
	 * @param string $presetId as the client names it: the id of a saved preset, `default_filter` for
	 *        the conditions of the form itself, or an empty string from a screen that has read no state
	 * @param array $fields values of the form, in the vocabulary of the web filter
	 */
	public function remember(string $presetId, array $fields): void
	{
		if ($presetId === '')
		{
			return;
		}

		$resolvedPresetId = FilterRequest::resolvePresetId($presetId);
		$settings = $this->settingsFor($resolvedPresetId, $fields);

		if ($settings === null)
		{
			return;
		}

		$this->rememberSettings($resolvedPresetId, $settings);
	}

	/**
	 * A list is asked for on every scroll of the screen, and `Options::save()` reaches the database with
	 * the whole set of presets of the user: a write repeated for conditions that are already there costs
	 * every request of the list and changes nothing.
	 *
	 * The pointer and the conditions are one answer: a filter applied under the pointer of another one
	 * is a move even when the conditions match, and conditions changed under the same pointer are a
	 * write even though the pointer stays.
	 */
	private function isAlreadyStored(string $presetId, array $settings): bool
	{
		if ((string)$this->storage->getCurrentFilterId() !== $presetId)
		{
			return false;
		}

		if ($presetId !== FilterRequest::TMP_PRESET_ID)
		{
			// a saved preset is written as the storage holds it, so there is nothing to compare: the
			// pointer already naming it is the whole question
			return true;
		}

		$stored = $this->storage->getFilterSettings($presetId);

		if (!is_array($stored))
		{
			return false;
		}

		$fields = ($settings['fields'] ?? []);
		// `Options` keeps the search string of the list beside the fields and the set of rows as the
		// string the web form reads, so what was written is compared where it is kept and not where it
		// travelled: read in the vocabulary of the request, every request looks like a change
		$search = (string)($fields[SavedFilterStorage::SEARCH_FIELD] ?? '');
		unset($fields[SavedFilterStorage::SEARCH_FIELD]);

		$rows = ($settings['rows'] ?? []);
		$rows = (is_array($rows) ? implode(',', $rows) : (string)$rows);

		return ($stored['fields'] ?? []) === $fields
			&& (string)($stored['filter_rows'] ?? '') === $rows
			&& (string)$this->storage->getSearchString() === $search;
	}

	/**
	 * The same write for a caller that has composed the settings itself.
	 *
	 * @param string $presetId resolved id of the preset: `tmp_filter` or the id of a saved one
	 * @param array $settings settings as they are to be stored
	 */
	public function rememberSettings(string $presetId, array $settings): void
	{
		if ($presetId === '' || $this->isAlreadyStored($presetId, $settings))
		{
			return;
		}

		$this->storage->setFilterSettings($presetId, $settings, true, false);
		$this->storage->save();
	}

	/**
	 * A saved preset is pointed at as the storage itself holds it: the values of the request are the
	 * form of this one request, and a preset rewritten out of them loses the rows the user added and
	 * left empty. A preset the storage does not know is not a preset to point at - the client names
	 * one deleted elsewhere, and a pointer at nothing shows the whole list to both clients.
	 */
	private function settingsFor(string $presetId, array $fields): ?array
	{
		if ($presetId === FilterRequest::TMP_PRESET_ID)
		{
			return ['fields' => $fields, 'rows' => FilterRequest::resolveRows($fields)];
		}

		$stored = ($this->storage->getPresets()[$presetId] ?? null);

		return is_array($stored) ? FilterRequest::buildSavedPresetSettings($presetId, $stored) : null;
	}
}
