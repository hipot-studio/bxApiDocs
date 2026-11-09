<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Guide;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * Reads the agent guide: the table of contents or one section. The flag and the access are the business
 * of the entry points.
 *
 * Data of the table of contents: `revision`, `language`, `rules`, `start`, `sections` (`id`, `title`, `bytes`).
 * Data of a section: `revision`, `language`, `id`, `title`, `text`, `next` (null after the last section).
 * The whole guide is the texts of the sections in the order of the table of contents, joined by an empty line.
 *
 * Nothing is cached between requests: the phrases are read anew, so a new edition reaches the next call.
 */
final class GetGuideOperation
{
	private const PART_SEPARATOR = "\n\n";
	private const MISSING_MARK = "\0missing";
	private const REVISION_LENGTH = 16;

	private readonly GuideCatalog $catalog;

	public function __construct(?GuideCatalog $catalog = null)
	{
		$this->catalog = $catalog ?? new GuideCatalog();
	}

	/**
	 * @param string|null $section Id of the section; null is the table of contents.
	 * @param string $language Language of the call; the guide answers in GuideCatalog::languageOf() of it.
	 */
	public function execute(?string $section, string $language): Result
	{
		$sections = $this->catalog->sections();
		$ids = array_column($sections, 'id');
		if ($section !== null && !in_array($section, $ids, true))
		{
			return (new Result())
				->addError(new Error(
					sprintf('Unknown guide section "%s". Allowed sections: %s.', $section, implode(', ', $ids)),
					FailureCode::NotFound->value,
				))
				->setData(['section' => $section, 'allowed' => $ids])
			;
		}

		$guideLanguage = $this->catalog->languageOf($language);
		// Loc looks phrases up by the calling file; the guide reads the lang file of the catalog explicitly.
		Loc::loadLanguageFile($this->catalog->getSourceFile(), $guideLanguage);
		// Loc fills a key missing in the file with the default language, so presence is checked in the file itself.
		$ownPhrases = $this->catalog->ownPhrases($guideLanguage);

		$rules = $this->phrase(GuideCatalog::RULES, $guideLanguage, $ownPhrases);
		$entries = [];
		foreach ($sections as $entry)
		{
			$entries[] = [
				'id' => $entry['id'],
				'title' => $this->phrase(GuideCatalog::phraseKey($entry['id'], null), $guideLanguage, $ownPhrases),
				'text' => $this->textOf($entry, $guideLanguage, $ownPhrases),
			];
		}
		$revision = $this->revisionOf($guideLanguage, $rules, $entries);

		if ($section === null)
		{
			if ($rules === null)
			{
				return (new Result())
					->addError(new Error(
						'The guide rules are broken in this edition of the module; retrying does not help.',
						FailureCode::Unavailable->value,
					))
				;
			}

			return (new Result())->setData([
				'revision' => $revision,
				'language' => $guideLanguage,
				'rules' => $rules,
				'start' => $ids[0],
				'sections' => array_map(
					static fn(array $entry): array => [
						'id' => $entry['id'],
						'title' => (string)$entry['title'],
						'bytes' => strlen((string)$entry['text']),
					],
					$entries,
				),
			]);
		}

		$index = array_search($section, $ids, true);
		$entry = $entries[$index];
		if ($entry['title'] === null || $entry['text'] === null)
		{
			return (new Result())
				->addError(new Error(
					sprintf(
						'The guide section "%s" is broken in this edition of the module; retrying does not help.',
						$section,
					),
					FailureCode::Unavailable->value,
				))
				->setData(['section' => $section])
			;
		}

		return (new Result())->setData([
			'revision' => $revision,
			'language' => $guideLanguage,
			'id' => $section,
			'title' => $entry['title'],
			'text' => $entry['text'],
			'next' => $ids[$index + 1] ?? null,
		]);
	}

	/**
	 * @param array{id: string, parts: int} $section
	 * @return string|null Null when a part is missing or empty.
	 */
	private function textOf(array $section, string $language, array $ownPhrases): ?string
	{
		$parts = [];
		for ($part = 1; $part <= $section['parts']; $part++)
		{
			$text = $this->phrase(GuideCatalog::phraseKey($section['id'], $part), $language, $ownPhrases);
			if ($text === null)
			{
				return null;
			}
			$parts[] = $text;
		}

		return implode(self::PART_SEPARATOR, $parts);
	}

	/**
	 * @return string|null Null when the own file of the language lacks the key or the phrase is empty.
	 */
	private function phrase(string $key, string $language, array $ownPhrases): ?string
	{
		if (!array_key_exists($key, $ownPhrases))
		{
			return null;
		}

		$text = trim((string)Loc::getMessage($key, null, $language));

		return $text === '' ? null : $text;
	}

	private function revisionOf(string $language, ?string $rules, array $entries): string
	{
		$pairs = array_map(
			static fn(array $entry): array => [
				$entry['id'],
				$entry['title'] ?? self::MISSING_MARK,
				$entry['text'] ?? self::MISSING_MARK,
			],
			$entries,
		);

		$source = Json::encode([$language, $rules ?? self::MISSING_MARK, $pairs]);

		return substr(hash('sha256', $source), 0, self::REVISION_LENGTH);
	}
}
