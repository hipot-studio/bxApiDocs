<?php

namespace Bitrix\Ldap\DI;

use Bitrix\Ldap\Settings;
use Bitrix\Ldap\Sync;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\NotImplementedException;

final class Container
{
	private static ?Container $instance = null;

	private ServiceLocator $locator;

	private function __construct()
	{
		$this->locator = ServiceLocator::getInstance();
	}

	public function getSettings(): Settings
	{
		return $this->get(Settings::class);
	}

	public function getSyncLogger(): Sync\Logger
	{
		return $this->get(Sync\Logger::class);
	}

	public function getSyncSessionManager(): Sync\SessionManager
	{
		return $this->get(Sync\SessionManager::class);
	}

	public function getSyncDeactivationStep(): Sync\DeactivationStep
	{
		return $this->get(Sync\DeactivationStep::class);
	}

	public function getSyncImportStep(): Sync\ImportStep
	{
		return $this->get(Sync\ImportStep::class);
	}

	public function get(string $idOrClass): mixed
	{
		return $this->locator->get($idOrClass);
	}

	public function addInstance(string $code, mixed $service): void
	{
		$this->locator->addInstance($code, $service);
	}

	public static function getInstance(): self
	{
		if (!isset(self::$instance))
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @throws NotImplementedException
	 */
	public function __serialize(): array
	{
		throw new NotImplementedException('Can not serialize singleton');
	}

	/**
	 * @throws NotImplementedException
	 */
	private function __clone()
	{
		throw new NotImplementedException('Can not clone singleton');
	}
}
