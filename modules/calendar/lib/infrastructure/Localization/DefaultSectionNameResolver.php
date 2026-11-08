<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Infrastructure\Localization;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SiteTable;

/**
 * Resolves the default calendar section phrases in an explicitly given language.
 *
 * Default sections must be named in the portal language, not in the language of
 * the request that happened to create them. All three phrases live in the lang
 * file of the sibling CCalendar class, so the file is loaded explicitly for the
 * target language: Loc::loadLazy() unsets a file from its lazy registry after
 * the first pass, so a cross-file lookup in a second language would otherwise
 * return null intermittently.
 *
 * The language is a constructor parameter, which keeps the class free of any DB
 * access; {@see self::forPortal()} is the factory that reads the portal one.
 */
final class DefaultSectionNameResolver
{
	private bool $languageFileLoaded = false;

	public function __construct(private readonly string $language)
	{
	}

	public static function forPortal(): self
	{
		return new self(SiteTable::getDefaultLanguageId() ?? LANGUAGE_ID);
	}

	public function getLocationName(): ?string
	{
		return $this->getPhrase('EC_DEF_SECT_LOCATION_CAL');
	}

	public function getGroupName(): ?string
	{
		return $this->getPhrase('EC_DEF_SECT_GROUP_CAL');
	}

	public function getDescription(): ?string
	{
		return $this->getPhrase('EC_DEF_SECT_DESC');
	}

	/**
	 * A language with no lang file of its own (and no family fallback) falls back
	 * to the request language: a nameless default section is worse than a section
	 * named in another language.
	 */
	private function getPhrase(string $code): ?string
	{
		$this->loadLanguageFile();

		$phrase = Loc::getMessage($code, null, $this->language);
		if ($phrase !== null)
		{
			return $phrase;
		}

		Loc::loadLanguageFile($this->langSourceFile());

		return Loc::getMessage($code);
	}

	private function loadLanguageFile(): void
	{
		if ($this->languageFileLoaded)
		{
			return;
		}

		Loc::loadLanguageFile($this->langSourceFile(), $this->language);
		$this->languageFileLoaded = true;
	}

	/**
	 * Path of the CCalendar file the phrases belong to. Taken from the loaded
	 * class rather than built relative to this file, so it survives moves of this
	 * class and follows a local/ override of the module.
	 */
	private function langSourceFile(): string
	{
		return (new \ReflectionClass(\CCalendar::class))->getFileName();
	}
}
