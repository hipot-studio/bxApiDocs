<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Install;

use Bitrix\Mail\Access\Install\AgentInstaller\InstallerFactory;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;

class AccessInstaller
{
	private const MODULE_ID = 'mail';
	public const MAIL_ACCESS_VERSION_KEY = 'mail_access_version';
	private const LOCK_NAME = 'mailbox_config_access_install';

	public static function install(): string
	{
		$connection = Application::getInstance()->getConnection();
		if (!$connection->lock(self::LOCK_NAME))
		{
			return '';
		}

		try
		{
			$accessInstaller = (new self());
			$currentVersion = $accessInstaller->getAccessVersion();

			while ($installer = InstallerFactory::getInstaller($currentVersion))
			{
				$installer->install();
				$accessInstaller->setActualAccessVersion(++$currentVersion);
			}
		}
		finally
		{
			$connection->unlock(self::LOCK_NAME);
		}

		return '';
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	public function setActualAccessVersion(int $version): void
	{
		Option::set(self::MODULE_ID, self::MAIL_ACCESS_VERSION_KEY, $version);
	}

	public function getAccessVersion(): int
	{
		return (int)Option::get(self::MODULE_ID, self::MAIL_ACCESS_VERSION_KEY, 0);
	}
}
