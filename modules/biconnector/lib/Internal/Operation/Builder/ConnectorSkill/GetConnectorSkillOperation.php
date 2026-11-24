<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\ConnectorSkill;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class GetConnectorSkillOperation
{
	private readonly ConnectorSkillCatalog $catalog;

	public function __construct(?ConnectorSkillCatalog $catalog = null)
	{
		$this->catalog = $catalog ?? new ConnectorSkillCatalog();
	}

	/**
	 * Reads the independent connector instruction without user or license state.
	 *
	 * @param string|null $section Null selects the table of contents.
	 * @param string $language A shipped language, otherwise English.
	 * @return Result
	 */
	public function execute(?string $section, string $language): Result
	{
		$sections = $this->catalog->sections();
		$ids = array_column($sections, 'id');
		if ($section !== null && !in_array($section, $ids, true))
		{
			return (new Result())->addError(new Error('The connector skill section was not found.', 'NOT_FOUND'));
		}
		$language = in_array($language, ConnectorSkillCatalog::SHIPPED_LANGUAGES, true) ? $language : 'en';
		try
		{
			$phrases = $this->catalog->ownPhrases($language);
		}
		catch (\Throwable)
		{
			return $this->unavailable();
		}
		foreach ($this->catalog->keys() as $key)
		{
			if (!isset($phrases[$key]) || trim($phrases[$key]) === '')
			{
				return $this->unavailable();
			}
		}

		$entries = [];
		foreach ($sections as $entry)
		{
			$parts = [];
			for ($part = 1; $part <= $entry['parts']; $part++)
			{
				$parts[] = trim($phrases[ConnectorSkillCatalog::phraseKey($entry['id'], $part)]);
			}
			$entries[] = [
				'id' => $entry['id'],
				'title' => trim($phrases[ConnectorSkillCatalog::phraseKey($entry['id'], null)]),
				'text' => implode("\n\n", $parts),
			];
		}
		$rules = trim($phrases[ConnectorSkillCatalog::RULES]);
		$revision = substr(hash('sha256', Json::encode([
			$language,
			$rules,
			array_map(static fn(array $entry): array => [$entry['id'], $entry['title'], $entry['text']], $entries),
		])), 0, 16);
		$data = [
			'revision' => $revision,
			'language' => $language,
		];
		if ($section === null)
		{
			return (new Result())->setData($data + [
				'rules' => $rules,
				'start' => $ids[0],
				'sections' => array_map(
					static fn(array $entry): array => ['id' => $entry['id'], 'title' => $entry['title']],
					$entries,
				),
			]);
		}
		$index = array_search($section, $ids, true);

		return (new Result())->setData($data + $entries[$index] + ['next' => $ids[$index + 1] ?? null]);
	}

	private function unavailable(): Result
	{
		return (new Result())->addError(new Error(
			'The connector skill edition is incomplete or unavailable. Update the module before retrying.',
			'UNAVAILABLE',
		));
	}
}
