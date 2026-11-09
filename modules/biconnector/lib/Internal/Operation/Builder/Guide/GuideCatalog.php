<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Guide;

use Bitrix\Main\IO\Path;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Localization\Translation;

/**
 * Sections of the agent guide. The text lives in the phrases of the lang file of this class:
 * a title and numbered parts per section, plus the general rules.
 *
 * A section id `ref-mcp` keeps its phrases under `BICONNECTOR_AGENT_GUIDE_REF_MCP_TITLE`,
 * `BICONNECTOR_AGENT_GUIDE_REF_MCP_1`, `BICONNECTOR_AGENT_GUIDE_REF_MCP_2`…
 */
final class GuideCatalog
{
	public const PREFIX = 'BICONNECTOR_AGENT_GUIDE_';
	public const RULES = self::PREFIX . 'RULES';
	// Shipped together with ru (notes/localization.md), so their files are complete by the tests of the module.
	public const SHIPPED_LANGUAGES = ['ru', 'en', 'de', 'kz'];
	public const MAX_PART_BYTES = 16384;
	public const MAX_SECTION_BYTES = 32768;
	// The source language keeps a margin for translations, which are longer.
	public const RU_PART_BYTES = 10240;
	public const RU_SECTION_BYTES = 20480;

	// The reference: `ref-mcp-<set>` describes the tools of a BI set, `ref-rest-<resource>` the builder methods
	// (biconnector/.ai/knowledge-base/notes/agent-guide.md).
	private const SECTIONS = [
		['id' => 'start', 'parts' => 2],
		['id' => 'ref-mcp-dashboards', 'parts' => 3],
		['id' => 'ref-mcp-charts', 'parts' => 2],
		['id' => 'ref-mcp-datasets', 'parts' => 2],
		['id' => 'ref-mcp-tables', 'parts' => 1],
		['id' => 'ref-rest-dashboard', 'parts' => 2],
		['id' => 'ref-rest-chart', 'parts' => 2],
		['id' => 'ref-rest-dataset', 'parts' => 1],
		['id' => 'ref-rest-table', 'parts' => 1],
		['id' => 'ref-rest-catalog', 'parts' => 1],
		['id' => 'ref-rest-guide', 'parts' => 1],
	];

	private const LANGUAGE_PATTERN = '/^[a-z]{2,3}$/';

	private readonly string $sourceFile;
	/** @var list<array{id: string, parts: int}> */
	private readonly array $sections;

	/**
	 * @param string|null $sourceFile The file whose lang files hold the phrases; this class by default.
	 * @param list<array{id: string, parts: int}>|null $sections
	 */
	public function __construct(?string $sourceFile = null, ?array $sections = null)
	{
		$this->sourceFile = Path::normalize($sourceFile ?? __FILE__);
		$this->sections = $sections ?? self::SECTIONS;
	}

	/**
	 * @return list<array{id: string, parts: int}> In the order of the table of contents; the first one is `start`.
	 */
	public function sections(): array
	{
		return $this->sections;
	}

	public function getSourceFile(): string
	{
		return $this->sourceFile;
	}

	/**
	 * @param int|null $part Number of the part from 1; null is the title of the section.
	 */
	public static function phraseKey(string $id, ?int $part): string
	{
		$key = self::PREFIX . strtoupper(str_replace('-', '_', $id));

		return $key . ($part === null ? '_TITLE' : '_' . $part);
	}

	/**
	 * @return list<string> Every phrase key of the guide: the rules, then titles and parts by section.
	 */
	public function keys(): array
	{
		$keys = [self::RULES];
		foreach ($this->sections as $section)
		{
			$keys[] = self::phraseKey($section['id'], null);
			for ($part = 1; $part <= $section['parts']; $part++)
			{
				$keys[] = self::phraseKey($section['id'], $part);
			}
		}

		return $keys;
	}

	/**
	 * The language the guide is served in for the language of a call: a shipped language or a language with
	 * a complete own file answers in itself, any other one in its default language. Loc would otherwise fill
	 * the gaps of an incomplete file with the default language and serve a mix.
	 */
	public function languageOf(string $lang): string
	{
		if (in_array($lang, self::SHIPPED_LANGUAGES, true))
		{
			return $lang;
		}

		$phrases = $this->ownPhrases($lang);
		foreach ($this->keys() as $key)
		{
			if (trim($phrases[$key] ?? '') === '')
			{
				return Loc::getDefaultLang($lang);
			}
		}

		return $lang;
	}

	/**
	 * Phrases of the own lang file of the language, without the default language merged in.
	 *
	 * @return array<string, string>
	 */
	public function ownPhrases(string $lang): array
	{
		$file = $this->langFileOf($lang);
		if ($file === null || !is_file($file))
		{
			return [];
		}

		$MESS = [];
		include $file;

		return array_filter($MESS, 'is_string');
	}

	private function langFileOf(string $lang): ?string
	{
		if (!preg_match(self::LANGUAGE_PATTERN, $lang))
		{
			return null;
		}

		// The same search for the lang directory as Loc does: the nearest `lang` up from the source file.
		$directory = dirname($this->sourceFile);
		while ($directory !== '' && $directory !== '/' && $directory !== '.')
		{
			if (is_dir($directory . '/lang'))
			{
				$relative = substr($this->sourceFile, strlen($directory));

				return Translation::convertLangPath($directory . '/lang/' . $lang . $relative, $lang);
			}
			$directory = dirname($directory);
		}

		return null;
	}
}
