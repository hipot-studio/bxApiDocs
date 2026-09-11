<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;

final class IntegratorFactory
{
	private static ?IntegratorInterface $instance = null;

	private static ?IntegratorInterface $selfHostedInstance = null;

	public static function getInstance(): IntegratorInterface
	{
		if (self::$instance === null)
		{
			self::$instance = self::createIntegrator();
		}

		return self::$instance;
	}

	/**
	 * The local integrator whatever the current mode is. Needed while the server is being connected: the portal
	 * is still in the cloud mode at that moment, and the cloud integrator would send the request elsewhere.
	 *
	 * The integrator of the current mode is deliberately not reused here: the same request could have asked the
	 * factory earlier, while the portal was still a cloud one, and then the cache holds the cloud integrator - which
	 * refuses this call locally. The test double has a seam of its own for the same reason.
	 */
	public static function getSelfHostedInstance(): IntegratorInterface
	{
		return self::$selfHostedInstance ?? SelfHostedIntegrator::getInstance();
	}

	/**
	 * Sets integrator instance. Useful for testing.
	 */
	public static function setInstance(?IntegratorInterface $integrator): void
	{
		self::$instance = $integrator;
	}

	/**
	 * Sets the integrator of the local mode, the one getSelfHostedInstance() answers with. Useful for testing.
	 */
	public static function setSelfHostedInstance(?IntegratorInterface $integrator): void
	{
		self::$selfHostedInstance = $integrator;
	}

	public static function reset(): void
	{
		self::$instance = null;
		self::$selfHostedInstance = null;
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
