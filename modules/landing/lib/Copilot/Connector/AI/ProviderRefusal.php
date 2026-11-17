<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\AI;

use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Main;

/**
 * The refusal of the provider: a send error from the allow list of the ai codes, the path to the
 * provider or the expired provider. The agreement, the tariff, the size of the images, the limits,
 * the cloud registration and the unavailable engine are not refusals: changing the provider is the
 * wrong advice for them.
 *
 * The mark lives in the params of the exception for any connector, only the image steps read it.
 */
final class ProviderRefusal
{
	public const CODES = [
		'AI_ENGINE_ERROR_PROVIDER',
		'AI_ENGINE_ERROR_OTHER',
		'CURRENT_PROVIDER_IS_EXPIRED',
	];

	public const PARAM_REFUSED = 'providerRefused';
	public const PARAM_PROVIDER = 'provider';

	/**
	 * Refusals in a row after which the image steps stop sending the rest.
	 */
	public const STREAK_LIMIT = 3;

	public static function isRefusal(Main\Error $error): bool
	{
		return in_array($error->getCode(), self::CODES, true);
	}

	/**
	 * The raw name of the provider that refused.
	 *
	 * @return string|null Null when the exception is not a refusal, the name otherwise: it may be empty.
	 */
	public static function findProvider(\Throwable $e): ?string
	{
		if (!$e instanceof GenerationException)
		{
			return null;
		}

		$params = $e->getParams();
		if (($params[self::PARAM_REFUSED] ?? false) !== true)
		{
			return null;
		}

		return (string)($params[self::PARAM_PROVIDER] ?? '');
	}
}
