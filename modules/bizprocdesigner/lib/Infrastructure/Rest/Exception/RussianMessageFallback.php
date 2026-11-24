<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Main\Localization\Loc;

/**
 * Reads the message of the refusal in ru while its phrase is authored in ru only.
 *
 * The core builds the message in the language of the portal or of the X-Bitrix-Rest-Response-Language header,
 * and Loc substitutes ru only for kz, by and uz. On en, de and ua the core would read a null phrase against a
 * string return type, and the TypeError would turn a 400 or a 404 into a 500 the agent cannot act on.
 *
 * The language is resolved before the core reads the phrase, so the replacement of the variable part stays on
 * the core path, and a translation of the current language wins over the fallback as soon as it is delivered.
 */
trait RussianMessageFallback
{
	/**
	 * The language the message is being built in. A refusal that names its variable part by a phrase of its
	 * own reads that phrase here: the core resolves the wrapping phrase in the language of the answer, and a
	 * part resolved anywhere else would answer in two languages once the translations arrive.
	 */
	private string $messageLanguage = 'ru';

	protected function getLocalMessage(string $languageCode): string
	{
		$this->messageLanguage = $this->resolveMessageLanguage($languageCode);

		return parent::getLocalMessage($this->messageLanguage);
	}

	/**
	 * A phrase of the same file, read in the language the message is being built in and falling back to ru
	 * the same way the message itself does.
	 *
	 * $replace is for a phrase with a variable part of its own. It is applied on BOTH reads, or a phrase
	 * that fell back to ru would answer with the placeholder still written out in it.
	 */
	protected function messagePhrase(string $code, ?array $replace = null): string
	{
		$phraseFile = (new \ReflectionClass($this->getClassWithPhrase()))->getFileName();

		Loc::loadLanguageFile($phraseFile, $this->messageLanguage);
		$message = Loc::getMessage($code, $replace, $this->messageLanguage);
		if ($message !== null)
		{
			return $message;
		}

		Loc::loadLanguageFile($phraseFile, 'ru');

		return (string)Loc::getMessage($code, $replace, 'ru');
	}

	private function resolveMessageLanguage(string $languageCode): string
	{
		$phraseFile = (new \ReflectionClass($this->getClassWithPhrase()))->getFileName();
		Loc::loadLanguageFile($phraseFile, $languageCode);

		return Loc::getMessage($this->getMessagePhraseCode(), null, $languageCode) === null ? 'ru' : $languageCode;
	}
}
