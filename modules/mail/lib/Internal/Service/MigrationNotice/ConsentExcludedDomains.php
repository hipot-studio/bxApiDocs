<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

/**
 * Domains of the public mail services: a mailbox on one of them is not counted by the consent panel of the
 * mailbox settings, since a transfer of a domain cannot move it. The domain is matched as a whole, a
 * subdomain of a listed one is a domain of its own.
 */
final class ConsentExcludedDomains
{
	private const DOMAINS = [
		'yandex.ru',
		'yandex.com.tr',
		'yandex.com',
		'yandex.by',
		'yandex.kz',
		'yandex.ua',
		'ya.ru',
		'narod.ru',
		'mail.ru',
		'list.ru',
		'bk.ru',
		'inbox.ru',
		'internet.ru',
		'xmail.ru',
		'mail.ua',
		'gmail.com',
		'googlemail.com',
		'outlook.com',
		'hotmail.com',
		'live.com',
		'rambler.ru',
		'icloud.com',
		'me.com',
		'proton.me',
		'protonmail.com',
		'yahoo.com',
	];

	public static function isExcludedDomain(string $domain): bool
	{
		return in_array(strtolower(trim($domain)), self::DOMAINS, true);
	}

	public static function isExcludedAddress(?string $email): bool
	{
		$atPosition = strrpos((string)$email, '@');

		return $atPosition !== false && self::isExcludedDomain(substr((string)$email, $atPosition + 1));
	}
}
