<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Rest;

use BackedEnum;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Rest\Public\Service\VibePlusMarketApplicationLimitProvider;
use Throwable;

final readonly class VibePlusLimitAdapter
{
	public function __construct(
		private ?object $provider = null,
	)
	{}

	public function getLimitProjection(): array
	{
		try
		{
			$provider = $this->resolveProvider('getProjection');
			if ($provider === null)
			{
				return self::unknownLimitProjection();
			}

			$projection = $provider->getProjection();
			if (!is_object($projection))
			{
				return self::unknownLimitProjection();
			}

			$methods = [
				'getState',
				'getCount',
				'getLimit',
				'isExceeded',
				'isInstallationBlocked',
			];
			foreach ($methods as $method)
			{
				if (!method_exists($projection, $method))
				{
					return self::unknownLimitProjection();
				}
			}

			$state = $projection->getState();
			$stateValue = $state instanceof BackedEnum ? $state->value : null;
			$limit = $projection->getLimit();
			$result = [
				'state' => $stateValue,
				'count' => $projection->getCount(),
				'limit' => $limit,
				'configuredLimit' => method_exists($projection, 'getConfiguredLimit')
					? $projection->getConfiguredLimit()
					: $limit,
				'exceeded' => $projection->isExceeded(),
				'installationBlocked' => $projection->isInstallationBlocked(),
			];

			return self::isProjectionValid($result) ? $result : self::unknownLimitProjection();
		}
		catch (Throwable)
		{
			return self::unknownLimitProjection();
		}
	}

	/**
	 * @return string[]
	 */
	public function getCountedApplicationCodes(): array
	{
		try
		{
			$provider = $this->resolveProvider('getCountedApplicationCodes');
			if ($provider === null)
			{
				return [];
			}

			$codes = $provider->getCountedApplicationCodes();
			if (!is_array($codes))
			{
				return [];
			}

			$result = [];
			foreach ($codes as $code)
			{
				if (is_string($code) && $code !== '')
				{
					$result[$code] = $code;
				}
			}

			return array_values($result);
		}
		catch (Throwable)
		{
			return [];
		}
	}

	private function resolveProvider(string $requiredMethod): ?object
	{
		$provider = $this->provider;
		if ($provider === null)
		{
			$providerClass = VibePlusMarketApplicationLimitProvider::class;
			if (!Loader::includeModule('rest') || !class_exists($providerClass))
			{
				return null;
			}

			$serviceLocator = ServiceLocator::getInstance();
			if (!$serviceLocator->has($providerClass))
			{
				return null;
			}

			$provider = $serviceLocator->get($providerClass);
		}

		return is_object($provider) && method_exists($provider, $requiredMethod) ? $provider : null;
	}

	private static function isProjectionValid(array $projection): bool
	{
		if (!in_array($projection['state'], ['finite', 'unlimited', 'notApplicable', 'unknown'], true))
		{
			return false;
		}

		if ($projection['count'] !== null && (!is_int($projection['count']) || $projection['count'] < 0))
		{
			return false;
		}

		if ($projection['limit'] !== null && (!is_int($projection['limit']) || $projection['limit'] < 0))
		{
			return false;
		}

		if (
			$projection['configuredLimit'] !== null
			&& (!is_int($projection['configuredLimit']) || $projection['configuredLimit'] < -1)
		)
		{
			return false;
		}

		foreach (['exceeded', 'installationBlocked'] as $field)
		{
			if ($projection[$field] !== null && !is_bool($projection[$field]))
			{
				return false;
			}
		}

		return true;
	}

	private static function unknownLimitProjection(): array
	{
		return [
			'state' => 'unknown',
			'count' => null,
			'limit' => null,
			'configuredLimit' => null,
			'exceeded' => null,
			'installationBlocked' => null,
		];
	}
}
