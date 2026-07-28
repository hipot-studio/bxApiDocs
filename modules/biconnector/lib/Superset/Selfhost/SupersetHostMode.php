<?php

namespace Bitrix\BIConnector\Superset\Selfhost;

use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Superset\Public\Commands\Server\DeleteServerCommand;
use Bitrix\Superset\Public\Providers\SelfHostedServerProvider;

final class SupersetHostMode
{
	private const MODE_OPTION = 'superset_mode';

	public const MODE_CLOUD = 'cloud';
	public const MODE_SELFHOSTED = 'selfhosted';

	public static function getMode(): string
	{
		return Option::get('biconnector', self::MODE_OPTION, self::MODE_CLOUD);
	}

	public static function isSelfHosted(): bool
	{
		return self::getMode() === self::MODE_SELFHOSTED;
	}

	public static function isCloud(): bool
	{
		return self::getMode() === self::MODE_CLOUD;
	}

	public static function setMode(string $mode): void
	{
		Option::set('biconnector', self::MODE_OPTION, $mode);
	}

	/**
	 * Checks if mode switch is allowed.
	 * For boxed installations, switching is always allowed (with data cleanup confirmation).
	 * For cloud (bitrix24), switching is not supported.
	 */
	public static function canSwitchMode(): bool
	{
		return !Loader::includeModule('bitrix24');
	}

	public static function handleSwitchMode(string $newMode): Result
	{
		$result = new Result();
		$currentMode = self::getMode();
		if ($newMode === $currentMode)
		{
			return $result;
		}

		if ($newMode !== self::MODE_CLOUD && $newMode !== self::MODE_SELFHOSTED)
		{
			$result->addError(new Error('Invalid mode specified'));

			return $result;
		}

		if (!self::canSwitchMode())
		{
			$result->addError(new Error(Loc::getMessage('BIC_SELFHOST_ERROR_CLOUD')));

			return $result;
		}

		SupersetInitializer::clearSupersetData();
		SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);
		AccessInstaller::install();

		if ($newMode === self::MODE_CLOUD && Loader::includeModule('superset'))
		{
			$server = SelfHostedServerProvider::findReference();
			(new DeleteServerCommand($server))->run();
		}

		self::setMode($newMode);
		IntegratorFactory::reset();

		return $result;
	}
}
