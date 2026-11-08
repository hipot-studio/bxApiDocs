<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Bitrix24;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Bitrix24\Public\Provider\Market\ApplicationLimitProvider;
use Bitrix\Bitrix24\Public\ValueObject\Market\ApplicationLimit;
use Throwable;

final readonly class ApplicationLimitAdapter
{
	public function __construct(
		private ?object $provider = null,
	)
	{}

	public function getLimitProjection(): array
	{
		try
		{
			$projection = $this->resolveProvider()?->getProjection();
			if (!$projection instanceof ApplicationLimit)
			{
				return self::unknownLimitProjection();
			}

			$state = match (true)
			{
				!$projection->isSuccess() => 'unknown',
				!$projection->isApplicable() => 'notApplicable',
				$projection->getLimit() === -1 => 'unlimited',
				default => 'finite',
			};

			return [
				'state' => $state,
				'count' => $projection->getCount(),
				'limit' => $state === 'finite' ? $projection->getLimit() : null,
				'configuredLimit' => $projection->getConfiguredLimit(),
				'exceeded' => $projection->isExceeded(),
				'installationBlocked' => $projection->isInstallationBlocked(),
			];
		}
		catch (Throwable)
		{
			return self::unknownLimitProjection();
		}
	}

	public function getCountedApplicationCodes(): array
	{
		try
		{
			$codes = $this->resolveProvider()?->getCountedApplicationCodes();
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

	private function resolveProvider(): ?object
	{
		if ($this->provider !== null)
		{
			return $this->provider;
		}

		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$serviceLocator = ServiceLocator::getInstance();
		$providerClass = ApplicationLimitProvider::class;

		return $serviceLocator->has($providerClass) ? $serviceLocator->get($providerClass) : null;
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
