<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Install\AgentInstaller;

class InstallerFactory
{
	/**
	 * @return array<int, string>
	 */
	public static function getVersionMap(): array
	{
		return [
			-1 => PermissionReInstaller::class,
			0 => DefaultPermissionInstaller::class,
			1 => MailboxCrmIntegrationPermissionInstaller::class,
		];
	}

	public static function getInstaller(int $version): ?AgentInstallerInterface
	{
		$installerClass = self::getVersionMap()[$version] ?? null;

		if ($installerClass && class_exists($installerClass))
		{
			return new $installerClass();
		}

		return null;
	}
}
