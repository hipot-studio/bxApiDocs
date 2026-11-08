<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UI\Filter\Options;

/**
 * Saved filters of one list (CONTRACT-API-02) on top of the filter storage shared with the web.
 *
 * The storage is `Bitrix\Main\UI\Filter\Options`, the same object the web list writes, so every
 * rule here exists to keep a write from mobile indistinguishable from a write from the web. The
 * caller only supplies the Options of its own list - how that list is addressed is a module concern.
 */
final class SavedFilterStorage
{
	/** the sort the web assumes for a preset stored without one (main.ui.filter class.php:735) */
	private const PRESET_SORT_DEFAULT = 0;

	/**
	 * The key the search string travels under, as Options::getFilter() writes it. Public: the same key
	 * is written by every provider that answers a filtered list, and a rename has to reach all of them.
	 */
	public const SEARCH_FIELD = 'FIND';

	public function __construct(private readonly Options $options)
	{
	}

	/**
	 * The right travels with the list and not with a preset: it is one operation of the portal, the
	 * same one changeForAll() asks for before it accepts an action. The client applies an action of a
	 * preset before the answer arrives, so an action it offers without the right is applied and taken
	 * back with a toast, whatever the user picks - the answer of the read is where that is decided.
	 *
	 * @return array{
	 *     presets: array<int, array>, currentPresetId: string, defaultPresetId: string,
	 *     canEditForAll: bool
	 * }
	 */
	public function getSavedFilters(): array
	{
		return [
			'presets' => $this->normalizeSavedFilters(),
			'currentPresetId' => (string)$this->options->getCurrentFilterId(),
			'defaultPresetId' => (string)$this->options->getDefaultFilterId(),
			'canEditForAll' => (bool)Options::isCurrentUserEditOtherSettings(),
		];
	}

	/**
	 * Content of the filter the list is showing right now: the values and the rows of the current
	 * preset, plus the search string of the list.
	 *
	 * The pointer alone (`currentPresetId`) says neither what is filtered nor which rows the form
	 * has to show, and both answers belong to the storage shared with the web: the web reads the
	 * same pair - values through Options::getFilter(), rows through the FIELDS of the preset
	 * (main.ui.filter class.php preparePreset()).
	 *
	 * The current preset may be `tmp_filter` - a condition applied in the web without saving - so
	 * the settings are read by the pointer instead of being looked up among the saved presets.
	 *
	 * @return array{fields: object, rows: string[]}
	 */
	public function getCurrentFilter(): array
	{
		$preset = $this->options->getFilterSettings((string)$this->options->getCurrentFilterId()) ?? [];
		$fields = is_array($preset['fields'] ?? null) ? $preset['fields'] : [];

		$search = $this->options->getSearchString();
		if ($search !== '')
		{
			$fields[self::SEARCH_FIELD] = $search;
		}

		return [
			'fields' => (object)$fields,
			'rows' => $this->resolveFormRows($preset),
		];
	}

	/**
	 * Fields the form shows, in the order the web shows them.
	 *
	 * The field list of the form belongs to the user and not to the preset: the web keeps it in the
	 * `default_filter` pseudo-preset (main.ui.filter filter.js saveFieldsSort(), restoreDefaultFields()),
	 * and a preset only fills the values, adding a field of its own that the list does not have.
	 *
	 * An empty list means the user has never changed it. Then the fields the provider marks `default`
	 * are the list - the answer of getFilterRows() of the web component - and that flag is already in
	 * the dictionary, so the client resolves this case without a second answer from here.
	 *
	 * @return string[]
	 */
	private function resolveFormRows(array $currentPreset): array
	{
		$formRows = $this->resolvePresetRows($this->options->getFilterSettings(Options::DEFAULT_FILTER) ?? []);
		if ($formRows === [])
		{
			return [];
		}

		return array_values(array_unique(array_merge($formRows, $this->resolvePresetRows($currentPreset))));
	}

	/**
	 * Writes the field list of the form: the fields the user keeps on the filter, in their order.
	 *
	 * It goes where the web keeps its own - the `default_filter` pseudo-preset (main.ui.filter
	 * filter.js saveFieldsSort()) - because that is the one place both forms read, see
	 * resolveFormRows(). Without this write the set composed on the phone lived in the session of
	 * the app alone: the web form never learned about a field added there, and the next start of
	 * the app read the fields of the provider back.
	 *
	 * An empty list means the fields the provider marks `default`, so it clears the stored one:
	 * that is what reset fields asks for, and the client resolves that case out of the
	 * dictionary without another answer from here.
	 *
	 * The pointer of the current preset is left alone: which fields the form shows and which
	 * filter is applied are two answers, and the web moves the pointer no more than this does.
	 *
	 * @param string[]|int[] $rows
	 */
	public function saveFieldSet(array $rows): Result
	{
		if (!$this->areValidRows($rows))
		{
			return self::fail(self::invalidRows());
		}

		$this->options->setFilterSettings(Options::DEFAULT_FILTER, ['rows' => array_values($rows)], false);
		$this->options->save();

		return self::ok(['rows' => $this->getCurrentFilter()['rows']]);
	}

	/**
	 * Writes the order of the saved filters: the position of a preset becomes its `sort`, exactly what
	 * the web writes when its presets are dragged (main.ui.filter filter.js, `sort: index`). The list
	 * is read back by that number (see sortPresets()), so nothing else keeps the order.
	 *
	 * The whole order is sent and written at once. A preset the order does not name - one created
	 * elsewhere while the screen was open - keeps the sort it has, the same way the web writes only
	 * the presets it knows about.
	 *
	 * Every id is checked before anything is written: a half-written order would leave two presets in
	 * one place, and that state survives every later read.
	 *
	 * @param string[] $presetIds in the order the list is to show them
	 */
	public function reorder(array $presetIds): Result
	{
		foreach ($presetIds as $presetId)
		{
			if (!is_string($presetId) || !$this->isValidPresetId($presetId))
			{
				return self::fail(self::invalidPresetId());
			}

			if (!$this->hasPreset($presetId))
			{
				return self::fail(self::presetNotFound());
			}
		}

		foreach (array_values($presetIds) as $position => $presetId)
		{
			$this->options->setFilterSettings($presetId, ['sort' => $position], false);
		}

		$this->options->save();

		return self::ok(['success' => true]);
	}

	/**
	 * Creates the preset when the id is new and overwrites it otherwise.
	 *
	 * An empty set of rows is the fields of the provider - the same answer it is everywhere else - so it
	 * is written as such and clears the rows the preset had. A caller naming no rows at all is a
	 * different matter: the preset keeps the set it has, the way the web writes only what it was given.
	 *
	 * @param string[]|int[]|null $rows null leaves the rows of the preset alone
	 */
	public function save(string $presetId, string $name, array $fields, ?array $rows): Result
	{
		if (!$this->isValidPresetId($presetId))
		{
			return self::fail(self::invalidPresetId());
		}

		if ($rows !== null && !$this->areValidRows($rows))
		{
			return self::fail(self::invalidRows());
		}

		$settings = ['fields' => $fields];
		if ($name !== '')
		{
			$settings['name'] = $name;
		}
		if ($rows !== null)
		{
			$settings['rows'] = array_values($rows);
		}
		if ($this->options->getFilterSettings($presetId) === null)
		{
			// a preset stored without sort is a zero for the web, which would put a filter
			// created on mobile above every existing one in the web list
			$settings['sort'] = $this->getNextPresetSort();
		}

		// saving a filter from mobile means the user applied it, so the preset becomes current
		// explicitly instead of being derived from request parameters outside of the contract
		$this->options->setFilterSettings($presetId, $settings, true, false);
		$this->options->save();

		return self::ok([
			'presetId' => $presetId,
			'presetData' => $this->mapPreset($presetId, $this->options->getFilterSettings($presetId) ?? []),
		]);
	}

	public function delete(string $presetId): Result
	{
		if (!$this->isValidPresetId($presetId))
		{
			return self::fail(self::invalidPresetId());
		}

		// a deleted default preset has to be remembered as such, otherwise the web
		// brings it back on the next Options construction
		$this->options->deleteFilter($presetId, array_key_exists($presetId, $this->options->getDefaultPresets()));
		$this->resetCurrentPreset($presetId);
		$this->resetPinnedPreset($presetId);
		$this->options->save();

		return self::ok(['success' => true]);
	}

	public function pin(string $presetId): Result
	{
		// the web unpins by pinning the pseudo-preset back (presets.js:271-274), so this is the one
		// action that accepts default_filter: without it pinning from mobile would be one-way
		$isUnpin = ($presetId === Options::DEFAULT_FILTER);

		if (!$isUnpin && !$this->isValidPresetId($presetId))
		{
			return self::fail(self::invalidPresetId());
		}

		if (!$isUnpin && !$this->hasPreset($presetId))
		{
			return self::fail(self::presetNotFound());
		}

		$this->options->pinPreset($presetId);
		$this->options->save();

		return self::ok(['success' => true]);
	}

	public function setForAll(string $presetId): Result
	{
		return $this->changeForAll($presetId, true);
	}

	/**
	 * Takes a shared preset back from everyone. Options::saveCommon() publishes the presets whose
	 * `for_all` is set, so dropping the flag of one preset republishes the common set without it -
	 * exactly what unchecking the for-all mark does in the web.
	 */
	public function unsetForAll(string $presetId): Result
	{
		return $this->changeForAll($presetId, false);
	}

	private function changeForAll(string $presetId, bool $forAll): Result
	{
		if (!Options::isCurrentUserEditOtherSettings())
		{
			return self::fail(new Error('Access denied.', 'ACCESS_DENIED'));
		}

		if (!$this->isValidPresetId($presetId))
		{
			return self::fail(self::invalidPresetId());
		}

		// setFilterSettings() creates a preset when it is missing, and saveForAll() would
		// then publish that empty preset to every user of the portal
		if (!$this->hasPreset($presetId))
		{
			return self::fail(self::presetNotFound());
		}

		// the flag is stored as `$settings['for_all'] === 'true'`, so anything else clears it
		$this->options->setFilterSettings($presetId, ['for_all' => $forAll ? 'true' : 'false'], false);
		$this->options->save();
		$this->options->saveForAll();

		return self::ok(['success' => true]);
	}

	/**
	 * Resets the filter to the presets the module ships with: their content is a server
	 * concern, so the client only names the list it wants restored.
	 *
	 * @return array{success: bool, currentPresetId: string, defaultPresetId: string}
	 */
	public function restore(): array
	{
		$this->options->restore($this->options->getDefaultPresets());
		$this->options->save();

		return [
			'success' => true,
			'currentPresetId' => (string)$this->options->getCurrentFilterId(),
			'defaultPresetId' => (string)$this->options->getDefaultFilterId(),
		];
	}

	/**
	 * Options::deleteFilter() leaves the pin on the deleted preset, and the pin is the preset the
	 * list opens on. The pointer would then name a preset the list no longer holds: the list opens
	 * on nothing, no row can be shown as pinned, and the state survives every reload - the web
	 * unpins by pinning the pseudo-preset back, so that is what happens here.
	 */
	private function resetPinnedPreset(string $deletedPresetId): void
	{
		if ($this->options->isPinned($deletedPresetId))
		{
			$this->options->pinPreset(Options::DEFAULT_FILTER);
		}
	}

	private function hasPreset(string $presetId): bool
	{
		return $this->options->getFilterSettings($presetId) !== null;
	}

	/**
	 * Options::deleteFilter() leaves the current preset pointer on the deleted id, and that pointer
	 * is shared with the web. The web resets it on the client (presets.js resetPreset() ->
	 * applyPreset('')), the mobile API has no "select preset" action to do the same.
	 */
	private function resetCurrentPreset(string $deletedPresetId): void
	{
		if ((string)$this->options->getCurrentFilterId() !== $deletedPresetId)
		{
			return;
		}

		$hadDefaultFilter = ($this->options->getFilterSettings(Options::DEFAULT_FILTER) !== null);

		$this->options->setFilterSettings(Options::DEFAULT_FILTER, [], true, false);

		// moving the pointer is only possible through setFilterSettings(), which also creates an
		// empty default_filter entry. The web builds its own named pseudo-preset only while that key
		// is absent (main.ui.filter class.php:753-756), so an entry we invented would replace it
		// with a nameless preset with no fields - and that state survives restore().
		if (!$hadDefaultFilter)
		{
			$this->options->deleteFilter(Options::DEFAULT_FILTER);
		}
	}

	/**
	 * Options::setFilterSettings() ignores an id that is empty by PHP rules ('' and '0' alike), so an
	 * unvalidated id turns a failed save into a success response. The two pseudo-presets are refused
	 * outright: they are the shared temporary filter and the "no preset" placeholder, not user data.
	 */
	private function isValidPresetId(string $presetId): bool
	{
		return !empty($presetId) && !self::isPseudoPreset($presetId);
	}

	private static function isPseudoPreset(string $presetId): bool
	{
		return in_array($presetId, [Options::DEFAULT_FILTER, Options::TMP_FILTER], true);
	}

	/**
	 * Options::setFilterSettings() trims every row, so a nested array turns a malformed request
	 * into a TypeError and a 500 instead of an error in the response envelope.
	 */
	private function areValidRows(array $rows): bool
	{
		foreach ($rows as $row)
		{
			if (!is_string($row) && !is_int($row))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Maps stored presets to the SavedFilter wire shape, dropping the two pseudo-presets.
	 *
	 * @return array<int, array{
	 *     id: string, name: string, fields: object, rows: string[], pinned: bool, forAll: bool
	 * }>
	 */
	private function normalizeSavedFilters(): array
	{
		$result = [];
		foreach ($this->sortPresets(self::stripPseudoPresets($this->options->getPresets() ?? [])) as $id => $preset)
		{
			$result[] = $this->mapPreset((string)$id, $preset);
		}

		return $result;
	}

	/**
	 * There is no "shipped by the module" flag on the wire: `default_presets` of the storage is the
	 * only candidate for that answer, and it is not one. The tasks filter puts the presets the user
	 * saved into it as well (Tasks\Helper\Filter::getAllPresets() merges the stored ones), so a
	 * filter created a minute ago is indistinguishable there from one the module ships. The flag
	 * still decides one thing on this side - whether a deletion has to be remembered, see delete().
	 *
	 * @return array{id: string, name: string, fields: object, rows: string[], pinned: bool, forAll: bool}
	 */
	private function mapPreset(string $presetId, array $preset): array
	{
		return [
			'id' => $presetId,
			'name' => (string)($preset['name'] ?? ''),
			'fields' => (object)($preset['fields'] ?? []),
			'rows' => $this->resolvePresetRows($preset),
			// isPinned() compares strictly, and a numeric preset id arrives from the array key as int
			'pinned' => $this->options->isPinned($presetId),
			'forAll' => (bool)($preset['for_all'] ?? false),
		];
	}

	/**
	 * The storage key order is not the display order: the web sorts presets by `sort` and counts a
	 * preset stored without one as a zero, which puts it first. Presets declared without `sort` are
	 * the norm for the socialnetwork lists (Integration\Main\UIFilter\Workgroup::getFilterPresetList).
	 */
	private function sortPresets(array $presets): array
	{
		uasort(
			$presets,
			static fn(array $a, array $b) => (int)($a['sort'] ?? self::PRESET_SORT_DEFAULT)
				<=> (int)($b['sort'] ?? self::PRESET_SORT_DEFAULT),
		);

		return $presets;
	}

	private function getNextPresetSort(): int
	{
		$maxSort = self::PRESET_SORT_DEFAULT;
		foreach ($this->options->getPresets() ?? [] as $preset)
		{
			$maxSort = max($maxSort, (int)($preset['sort'] ?? self::PRESET_SORT_DEFAULT));
		}

		return $maxSort + 1;
	}

	/**
	 * A preset the module ships with is declared without `filter_rows`, and its field order comes
	 * from the field values instead - Options::fetchPresetFields() resolves both cases as the web does.
	 *
	 * A stored `filter_rows` is not a list of field ids either: it is written by whoever saved the
	 * preset, and the mobile list of CRM has been saving the keys of the serialized values there
	 * (`OPPORTUNITY_from`, `CLOSEDATE_datesel`). Such a row names no field, so the rows are put
	 * through the same reduction the web applies to the value keys.
	 *
	 * @return string[]
	 */
	private function resolvePresetRows(array $preset): array
	{
		$preset['fields'] ??= [];

		$rows = array_filter(
			array_map(static fn($row) => trim((string)$row), Options::fetchPresetFields($preset)),
			static fn(string $row) => $row !== '',
		);

		return array_values(Options::getRowsFromFields(array_fill_keys($rows, '')));
	}

	/**
	 * The two pseudo-presets are the shared temporary filter and the "no preset" placeholder, not
	 * user data. Public because the legacy preset actions of the modules answer the same question.
	 */
	public static function stripPseudoPresets(array $presets): array
	{
		unset($presets[Options::DEFAULT_FILTER], $presets[Options::TMP_FILTER]);

		return $presets;
	}

	private static function ok(array $data): Result
	{
		return (new Result())->setData($data);
	}

	private static function fail(Error $error): Result
	{
		return (new Result())->addError($error);
	}

	private static function invalidPresetId(): Error
	{
		return new Error('Invalid preset id.', 'INVALID_PRESET_ID');
	}

	private static function presetNotFound(): Error
	{
		return new Error('Preset not found.', 'PRESET_NOT_FOUND');
	}

	private static function invalidRows(): Error
	{
		return new Error('Invalid filter rows.', 'INVALID_FILTER_DATA');
	}
}
