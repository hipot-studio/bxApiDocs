<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\ConnectorSkill;

final class ConnectorSkillCatalog
{
	public const PREFIX = 'BICONNECTOR_CONNECTOR_AGENT_SKILL_';
	public const RULES = self::PREFIX . 'RULES';
	public const SHIPPED_LANGUAGES = ['ru', 'en', 'de', 'kz'];

	private const SECTIONS = [
		['id' => 'start', 'parts' => 1],
		['id' => 'data', 'parts' => 1],
		['id' => 'connect', 'parts' => 2],
		['id' => 'change', 'parts' => 1],
		['id' => 'lifecycle', 'parts' => 1],
		['id' => 'errors', 'parts' => 1],
	];

	public function __construct(private readonly string $sourceFile = __FILE__)
	{
	}

	/** @return list<array{id: string, parts: int}> Sections in reading order. */
	public function sections(): array
	{
		return self::SECTIONS;
	}

	public static function phraseKey(string $id, ?int $part): string
	{
		return self::PREFIX . strtoupper($id) . ($part === null ? '_TITLE' : '_' . $part);
	}

	/** @return list<string> Own phrase keys, without the existing builder guide. */
	public function keys(): array
	{
		$keys = [self::RULES];
		foreach (self::SECTIONS as $section)
		{
			$keys[] = self::phraseKey($section['id'], null);
			for ($part = 1; $part <= $section['parts']; $part++)
			{
				$keys[] = self::phraseKey($section['id'], $part);
			}
		}

		return $keys;
	}

	/** @return array<string, string> Phrases from the own file, with no Loc fallback or global mutation. */
	public function ownPhrases(string $language): array
	{
		if (!in_array($language, self::SHIPPED_LANGUAGES, true))
		{
			return [];
		}

		$directory = dirname($this->sourceFile);
		while ($directory !== '/' && $directory !== '.' && $directory !== '')
		{
			if (is_dir($directory . '/lang'))
			{
				$file = $directory . '/lang/' . $language . substr($this->sourceFile, strlen($directory));
				if (!is_file($file))
				{
					return [];
				}
				$MESS = [];
				include $file;

				return array_filter($MESS, 'is_string');
			}
			$directory = dirname($directory);
		}

		return [];
	}
}
