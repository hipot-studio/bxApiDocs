<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\Multifield\Type;
use Bitrix\Crm\V2\Public\Entity\Dictionary\CommunicationChannel;
use Bitrix\Main\Localization\Loc;

/**
 * Resolves the captions of a communication value type in a requested language.
 *
 * The phrase codes are spelled out value by value on purpose: the naming rule
 * CRM_FM_ENTITY_{TYPE}_{VALUE} breaks for four of them, and for OPENLINE and IMOL a computed code
 * would still name an existing phrase - the wrong one. The map is checked against legacy by
 * CommunicationTypeTitleParityTest.
 *
 * The language rule differs from AddressTypeTitleResolver: the caption is read in the language of
 * the answer alone, leaving the fallback to the language resources themselves. Loc includes the file
 * of Loc::getDefaultLang() of the requested identifier first and the file of the identifier itself
 * over it, so a phrase the requested language misses arrives in that default language - en for
 * anything unknown, ru for kz, by and uz. An identifier that is not a pair of lowercase letters is
 * read as en, and a value neither file carries a phrase for gets a null caption instead of its own
 * code.
 */
final class CommunicationTypeTitleResolver
{
	private const FALLBACK_LANGUAGE_ID = 'en';

	/**
	 * Channel and value type to the codes of the full and the short caption.
	 */
	private const MESSAGE_CODES = [
		Type\Email::ID => [
			Type\Email::VALUE_TYPE_WORK => ['CRM_FM_ENTITY_EMAIL_WORK', 'CRM_FM_ENTITY_EMAIL_WORK_SHORT'],
			Type\Email::VALUE_TYPE_HOME => ['CRM_FM_ENTITY_EMAIL_HOME', 'CRM_FM_ENTITY_EMAIL_HOME_SHORT'],
			Type\Email::VALUE_TYPE_MAILING => ['CRM_FM_ENTITY_EMAIL_MAILING1', 'CRM_FM_ENTITY_EMAIL_MAILING_SHORT'],
			Type\Email::VALUE_TYPE_OTHER => ['CRM_FM_ENTITY_EMAIL_OTHER', 'CRM_FM_ENTITY_EMAIL_OTHER_SHORT'],
		],
		Type\Phone::ID => [
			Type\Phone::VALUE_TYPE_WORK => ['CRM_FM_ENTITY_PHONE_WORK', 'CRM_FM_ENTITY_PHONE_WORK_SHORT'],
			Type\Phone::VALUE_TYPE_MOBILE => ['CRM_FM_ENTITY_PHONE_MOBILE', 'CRM_FM_ENTITY_PHONE_MOBILE_SHORT'],
			Type\Phone::VALUE_TYPE_FAX => ['CRM_FM_ENTITY_PHONE_FAX', 'CRM_FM_ENTITY_PHONE_FAX_SHORT'],
			Type\Phone::VALUE_TYPE_HOME => ['CRM_FM_ENTITY_PHONE_HOME', 'CRM_FM_ENTITY_PHONE_HOME_SHORT'],
			Type\Phone::VALUE_TYPE_PAGER => ['CRM_FM_ENTITY_PHONE_PAGER', 'CRM_FM_ENTITY_PHONE_PAGER_SHORT'],
			Type\Phone::VALUE_TYPE_MAILING => ['CRM_FM_ENTITY_PHONE_MAILING', 'CRM_FM_ENTITY_PHONE_MAILING_SHORT'],
			Type\Phone::VALUE_TYPE_OTHER => ['CRM_FM_ENTITY_PHONE_OTHER', 'CRM_FM_ENTITY_PHONE_OTHER_SHORT'],
		],
		Type\Im::ID => [
			Type\Im::VALUE_TYPE_FACEBOOK => ['CRM_FM_ENTITY_IM_FACEBOOK', 'CRM_FM_ENTITY_IM_FACEBOOK_SHORT'],
			Type\Im::VALUE_TYPE_TELEGRAM => ['CRM_FM_ENTITY_IM_TELEGRAM', 'CRM_FM_ENTITY_IM_TELEGRAM_SHORT'],
			Type\Im::VALUE_TYPE_VK => ['CRM_FM_ENTITY_IM_VK', 'CRM_FM_ENTITY_IM_VK_SHORT'],
			Type\Im::VALUE_TYPE_SKYPE => ['CRM_FM_ENTITY_IM_SKYPE', 'CRM_FM_ENTITY_IM_SKYPE_SHORT'],
			Type\Im::VALUE_TYPE_VIBER => ['CRM_FM_ENTITY_IM_VIBER', 'CRM_FM_ENTITY_IM_VIBER_SHORT'],
			Type\Im::VALUE_TYPE_INSTAGRAM => ['CRM_FM_ENTITY_IM_INSTAGRAM', 'CRM_FM_ENTITY_IM_INSTAGRAM_SHORT'],
			Type\Im::VALUE_TYPE_BITRIX24 => [
				'CRM_FM_ENTITY_IM_BITRIX24_MSGVER_1',
				'CRM_FM_ENTITY_IM_BITRIX24_SHORT_MSGVER_1',
			],
			Type\Im::VALUE_TYPE_OPENLINE => ['CRM_FM_ENTITY_IM_WIDGET', 'CRM_FM_ENTITY_IM_WIDGET'],
			Type\Im::VALUE_TYPE_IMOL => ['CRM_FM_ENTITY_IM_OPENLINE', 'CRM_FM_ENTITY_IM_OPENLINE_SHORT'],
			Type\Im::VALUE_TYPE_ICQ => ['CRM_FM_ENTITY_IM_ICQ', 'CRM_FM_ENTITY_IM_ICQ_SHORT'],
			Type\Im::VALUE_TYPE_MSN => ['CRM_FM_ENTITY_IM_MSN', 'CRM_FM_ENTITY_IM_MSN_SHORT'],
			Type\Im::VALUE_TYPE_JABBER => ['CRM_FM_ENTITY_IM_JABBER', 'CRM_FM_ENTITY_IM_JABBER_SHORT'],
			Type\Im::VALUE_TYPE_OTHER => ['CRM_FM_ENTITY_IM_OTHER', 'CRM_FM_ENTITY_IM_OTHER_SHORT'],
		],
		Type\Web::ID => [
			Type\Web::VALUE_TYPE_WORK => ['CRM_FM_ENTITY_WEB_WORK', 'CRM_FM_ENTITY_WEB_WORK_SHORT'],
			Type\Web::VALUE_TYPE_HOME => ['CRM_FM_ENTITY_WEB_HOME', 'CRM_FM_ENTITY_WEB_HOME_SHORT'],
			Type\Web::VALUE_TYPE_FACEBOOK => ['CRM_FM_ENTITY_WEB_FACEBOOK', 'CRM_FM_ENTITY_WEB_FACEBOOK_SHORT'],
			Type\Web::VALUE_TYPE_VK => ['CRM_FM_ENTITY_WEB_VK', 'CRM_FM_ENTITY_WEB_VK_SHORT'],
			Type\Web::VALUE_TYPE_LIVEJOURNAL => ['CRM_FM_ENTITY_WEB_LIVEJOURNAL', 'CRM_FM_ENTITY_WEB_LIVEJOURNAL_SHORT'],
			Type\Web::VALUE_TYPE_TWITTER => ['CRM_FM_ENTITY_WEB_TWITTER', 'CRM_FM_ENTITY_WEB_TWITTER_SHORT'],
			Type\Web::VALUE_TYPE_OTHER => ['CRM_FM_ENTITY_WEB_OTHER', 'CRM_FM_ENTITY_WEB_OTHER_SHORT'],
		],
	];

	private static ?string $sourceFile = null;

	/**
	 * The phrases of the source file by language identifier: the caption of every value of a channel
	 * comes from the same file, and its contents do not change while the request is being served.
	 *
	 * @var array<string, array>
	 */
	private array $messagesByLanguage = [];

	/**
	 * @return array{0: ?string, 1: ?string} The full and the short caption, null where there is no phrase.
	 */
	public function resolve(CommunicationChannel $channel, string $valueType, string $languageId): array
	{
		$codes = self::MESSAGE_CODES[$channel->value][$valueType] ?? null;
		if ($codes === null)
		{
			return [null, null];
		}

		[$fullCode, $shortCode] = $codes;
		$messages = $this->getMessages($this->sanitizeLanguageId($languageId));

		return [$this->getPhrase($messages, $fullCode), $this->getPhrase($messages, $shortCode)];
	}

	/**
	 * The identifier comes from an external header and ends up in the path of a language file, so
	 * anything but a pair of lowercase letters is replaced instead of being passed through.
	 */
	private function sanitizeLanguageId(string $languageId): string
	{
		return preg_match('/^[a-z]{2}$/D', $languageId) === 1 ? $languageId : self::FALLBACK_LANGUAGE_ID;
	}

	private function getMessages(string $languageId): array
	{
		return $this->messagesByLanguage[$languageId] ??= Loc::loadLanguageFile($this->getSourceFile(), $languageId);
	}

	private function getPhrase(array $messages, string $code): ?string
	{
		$phrase = $messages[$code] ?? null;
		if (!is_string($phrase) || trim($phrase) === '')
		{
			return null;
		}

		return $phrase;
	}

	private function getSourceFile(): string
	{
		return self::$sourceFile ??= (string)(new \ReflectionClass(\CCrmFieldMulti::class))->getFileName();
	}
}
