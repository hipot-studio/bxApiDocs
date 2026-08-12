<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Main\Config\Option;

final class ProviderAccessRestriction
{
	private const YANDEX_FREE_DOMAIN_PATTERN = '/^(yandex\.[a-z]{2,}|ya\.ru|narod\.ru)$/i';

	private const FEATURE_OPTION_NAME = 'provider_restriction_notice';

	public static function isFeatureEnabled(): bool
	{
		return Option::get('mail', self::FEATURE_OPTION_NAME, 'N') === 'Y';
	}

	public static function isRestricted(?string $serviceName, ?string $email): bool
	{
		return match (strtolower(trim((string)$serviceName)))
		{
			'yandex' => self::isYandexFreeDomain($email),
			'mail.ru', 'mailru' => true,
			default => false,
		};
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

	private static function isYandexFreeDomain(?string $email): bool
	{
		if ($email === null)
		{
			return true;
		}

		$atPos = strrpos($email, '@');
		if ($atPos === false)
		{
			return false;
		}

		$domain = strtolower(substr($email, $atPos + 1));

		return (bool)preg_match(self::YANDEX_FREE_DOMAIN_PATTERN, $domain);
	}
}
