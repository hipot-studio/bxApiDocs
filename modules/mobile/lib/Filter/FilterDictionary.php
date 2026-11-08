<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\DateType;
use Bitrix\Main\UI\Filter\NumberType;

/**
 * Words of the filter controls, sent once per answer of the dictionary (CONTRACT-DTO-01): the periods
 * of a date, the comparisons of a number, yes and no, the months.
 *
 * Every word comes from the web filter library, the only place the portal words these controls
 * (Main\UI\Filter\Field, FieldAdapter). A second wording on the mobile side drifts away from the web
 * the moment either is edited, and every language of it has to be translated again - so the mobile
 * form draws what the web says, in the order the web says it.
 *
 * The vocabulary belongs to no list: it is the same for a CRM funnel and for a task list, which is
 * why it stands next to the fields instead of being repeated inside each of them.
 */
final class FilterDictionary
{
	/**
	 * The library files whose phrases word the controls. Loaded by path rather than asked for through
	 * Loc::getMessage(): a phrase of another module is not in scope of this file, and the raw list
	 * makes the source of every word visible.
	 */
	private const FIELD_PHRASES_FILE = '/bitrix/modules/main/lib/UI/Filter/Field.php';
	private const ADAPTER_PHRASES_FILE = '/bitrix/modules/main/lib/UI/Filter/FieldAdapter.php';

	private const MONTH_COUNT = 12;

	/**
	 * Values of a boolean field, as the client sends them back (CONTRACT-DTO-01), to the phrases the
	 * web checkbox control is worded with.
	 */
	private const BOOLEAN_PHRASE = [
		'Y' => 'MAIN_UI_FILTER__YES',
		'N' => 'MAIN_UI_FILTER__NO',
	];

	/** @var array<string, string>|null */
	private ?array $fieldPhrases = null;

	/** @var array<string, string>|null */
	private ?array $adapterPhrases = null;

	/**
	 * @return array{
	 *     datePresets: array<int, array{id: string, name: string}>,
	 *     numberOperators: array<int, array{id: string, name: string}>,
	 *     booleanValues: array<int, array{id: string, name: string}>,
	 *     months: array<int, array{id: string, name: string}>,
	 *     yearsSwitcher: string,
	 *     langId: string,
	 * }
	 */
	public function toArray(): array
	{
		return [
			'datePresets' => $this->getDatePresets(),
			'numberOperators' => $this->getNumberOperators(),
			'booleanValues' => $this->getBooleanValues(),
			'months' => $this->getMonths(),
			'yearsSwitcher' => $this->fieldPhrase('MAIN_UI_FILTER_FIELD_SUBTYPE_CUSTOM_DATE_YEARS_SWITCHER_YES'),
			// the client keeps the vocabulary and says which language it holds; the words themselves are
			// then sent only when that answer no longer matches the portal, see isKeptVocabularyFresh()
			'langId' => Loc::getCurrentLang(),
		];
	}

	/**
	 * Whether the vocabulary the client already holds still stands. The words are the same for every
	 * list of every module and the client keeps them, so resending them with each answer is what a
	 * short list pays for and never uses: a list of twelve fields saves 281 bytes on the periods it no
	 * longer repeats and would pay one and a half kilobytes for the words of them.
	 *
	 * The language is the whole question: a vocabulary is only stale when the portal words its filter
	 * differently than when the client read it.
	 *
	 * @param string $langId language of the vocabulary the client holds, empty when it holds none
	 */
	public static function isKeptVocabularyFresh(string $langId): bool
	{
		return $langId !== '' && $langId === Loc::getCurrentLang();
	}

	/**
	 * Periods of a date in the order the web control offers them. NONE is the "not set" placeholder
	 * of that control and not a period: the mobile form clears a field by a row of its own.
	 *
	 * A field narrows this list by the periods its provider excludes - that part stays in the
	 * descriptor of the field, see FieldDescriptorNormalizer.
	 *
	 * @return array<int, array{id: string, name: string}>
	 */
	private function getDatePresets(): array
	{
		$presets = array_values(array_diff(DateType::getList(), [DateType::NONE]));

		return $this->worded($presets, static fn(string $id): string => 'MAIN_UI_FILTER_FIELD_SUBTYPE_' . $id);
	}

	/**
	 * Comparisons of a number. The web keys them by the name of the constant and words them by that
	 * name as well, while the value the client sends back is the one behind it (`exact` for SINGLE).
	 *
	 * @return array<int, array{id: string, name: string}>
	 */
	private function getNumberOperators(): array
	{
		$result = [];
		foreach (NumberType::getList() as $constant => $operator)
		{
			$result[] = ['id' => (string)$operator, 'name' => $this->fieldPhrase('MAIN_UI_FILTER__NUMBER_' . $constant)];
		}

		return $this->onlyWorded($result);
	}

	/**
	 * @return array<int, array{id: string, name: string}>
	 */
	private function getBooleanValues(): array
	{
		$result = [];
		foreach (self::BOOLEAN_PHRASE as $value => $phraseId)
		{
			$result[] = ['id' => $value, 'name' => $this->adapterPhrase($phraseId)];
		}

		return $this->onlyWorded($result);
	}

	/**
	 * Months by number, worded as the web filter words them.
	 *
	 * Quarters are deliberately absent next to them: the web numbers a quarter in roman numerals
	 * ("I"-"IV"), while the mobile control reads the number itself into a phrase of its own ("2nd
	 * quarter 2026"). That is a form of writing and not a word of the portal, so there is
	 * nothing to take from the web here.
	 *
	 * @return array<int, array{id: string, name: string}>
	 */
	private function getMonths(): array
	{
		return $this->worded(
			array_map('strval', range(1, self::MONTH_COUNT)),
			static fn(string $id): string => 'MAIN_UI_FILTER_FIELD_MONTH_' . $id,
		);
	}

	/**
	 * @param string[] $ids
	 * @param callable(string): string $phraseId
	 * @return array<int, array{id: string, name: string}>
	 */
	private function worded(array $ids, callable $phraseId): array
	{
		$result = [];
		foreach ($ids as $id)
		{
			$result[] = ['id' => $id, 'name' => $this->fieldPhrase($phraseId($id))];
		}

		return $this->onlyWorded($result);
	}

	/**
	 * An entry the portal has no word for is left out instead of being sent unworded: a row with no
	 * text reads as an empty choice, and the client has no wording of its own to fall back on.
	 *
	 * @param array<int, array{id: string, name: string}> $entries
	 * @return array<int, array{id: string, name: string}>
	 */
	private function onlyWorded(array $entries): array
	{
		return array_values(array_filter($entries, static fn(array $entry): bool => trim($entry['name']) !== ''));
	}

	private function fieldPhrase(string $phraseId): string
	{
		$this->fieldPhrases ??= $this->loadPhrases(self::FIELD_PHRASES_FILE);

		return (string)($this->fieldPhrases[$phraseId] ?? '');
	}

	private function adapterPhrase(string $phraseId): string
	{
		$this->adapterPhrases ??= $this->loadPhrases(self::ADAPTER_PHRASES_FILE);

		return (string)($this->adapterPhrases[$phraseId] ?? '');
	}

	/**
	 * @return array<string, string>
	 */
	private function loadPhrases(string $relativePath): array
	{
		return Loc::loadLanguageFile(Application::getDocumentRoot() . $relativePath) ?? [];
	}
}
