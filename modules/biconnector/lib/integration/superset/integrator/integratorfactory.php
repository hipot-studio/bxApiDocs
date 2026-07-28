<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;

final class IntegratorFactory
{
	private static ?IntegratorInterface $instance = null;

	public static function getInstance(): IntegratorInterface
	{
		if (self::$instance === null)
		{
			self::$instance = self::createIntegrator();
		}

		return self::$instance;
	}

	/**
	 * Sets integrator instance. Useful for testing.
	 */
	public static function setInstance(?IntegratorInterface $integrator): void
	{
		self::$instance = $integrator;
	}

	public static function reset(): void
	{
		self::$instance = null;
	}

	private static function createIntegrator(): IntegratorInterface
	{
		if (SupersetHostMode::isSelfHosted())
		{
			return SelfHostedIntegrator::getInstance();
		}

		return ProxyIntegrator::getInstance();
	}
}
