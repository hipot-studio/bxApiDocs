<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Main\Config\Option;

final class ProviderAccessRestriction
{
	/** The tail takes several labels, for zones like yandex.com.tr. */
	private const YANDEX_FREE_DOMAIN_PATTERN = '/^(?:yandex(?:\.[a-z]{2,})+|ya\.ru|narod\.ru)$/i';

	private const MAILRU_FREE_DOMAIN_PATTERN = '/^(?:(?:mail|list|bk|inbox|internet|xmail)\.ru|mail\.ua)$/i';

	/** Owned by {@see \Bitrix\MailService\Internal\Service\FreemailDomainClassifier} and copied here. */
	private const FREE_DOMAIN_PATTERNS = [
		'yandex' => self::YANDEX_FREE_DOMAIN_PATTERN,
		'mailru' => self::MAILRU_FREE_DOMAIN_PATTERN,
	];

	private const FEATURE_OPTION_NAME = 'provider_restriction_notice';

	public static function isFeatureEnabled(): bool
	{
		return Option::get('mail', self::FEATURE_OPTION_NAME, 'Y') === 'Y';
	}

	/**
	 * Whether the provider of this mailbox has closed the standard protocols to outside services.
	 *
	 * The address is not asked about: both providers closed them for everybody, free address and
	 * company domain alike. Yandex was read as restricting its free addresses only until 2026-09-30,
	 * when the product said it restricts the rest the same way.
	 */
	public static function isRestricted(?string $serviceName): bool
	{
		return self::resolveProviderName($serviceName) !== null;
	}

	/**
	 * Mind the difference from isRestricted(): that one is true on every mailbox of the two providers,
	 * this one only on the company domains a migration to Bitrix24 Mail can move. A free address is not
	 * moved by a handover of a domain, so it is answered false here - its owner is told what happened
	 * and offered no migration. The plan of the provider is not part of the question: a connected
	 * mailbox does not tell it.
	 */
	public static function isMigrationRestricted(?string $serviceName, ?string $email): bool
	{
		$provider = self::resolveProviderName($serviceName);
		$domain = self::extractDomain($email);

		if ($provider === null || $domain === null)
		{
			return false;
		}

		return !self::isFreeDomain($provider, $domain);
	}

	public static function resolveProviderName(?string $serviceName): ?string
	{
		return match (strtolower(trim((string)$serviceName)))
		{
			'yandex' => 'yandex',
			'mail.ru', 'mailru' => 'mailru',
			default => null,
		};
	}

	private static function isFreeDomain(string $provider, string $domain): bool
	{
		$pattern = self::FREE_DOMAIN_PATTERNS[$provider] ?? null;

		return $pattern !== null && preg_match($pattern, $domain) === 1;
	}

	private static function extractDomain(?string $email): ?string
	{
		if ($email === null)
		{
			return null;
		}

		$atPos = strrpos($email, '@');
		if ($atPos === false)
		{
			return null;
		}

		$domain = substr($email, $atPos + 1);

		return $domain === '' ? null : strtolower($domain);
	}
}
