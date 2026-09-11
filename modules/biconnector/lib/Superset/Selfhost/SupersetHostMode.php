<?php

namespace Bitrix\BIConnector\Superset\Selfhost;

use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\SelfHostedConnectionService;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicense;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseState;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseNotifier;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseSync;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class SupersetHostMode
{
	private const MODE_OPTION = 'superset_mode';

	public const MODE_CLOUD = 'cloud';
	public const MODE_SELFHOSTED = 'selfhosted';

	private const ALLOWED_REGIONS = ['ru'];

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

	public static function canSwitchMode(): bool
	{
		return !Loader::includeModule('bitrix24');
	}

	public static function checkSelfHostedRegion(): Result
	{
		$result = new Result();
		if (!in_array(Application::getInstance()->getLicense()->getRegion(), self::ALLOWED_REGIONS, true))
		{
			$result->addError(new Error(Loc::getMessage('BIC_SELFHOST_ERROR_REGION')));
		}

		return $result;
	}

	/**
	 * The only place the edition gate is decided: the switch, the availability chain and the settings page ask the
	 * same question, and an edition registry without the extended code is the whole answer - a term of the
	 * extension does not make an unsuitable edition suitable.
	 */
	public static function checkSelfHostedEdition(): Result
	{
		$result = new Result();
		if (!SelfHostedLicense::getInstance()->isAllowedByEdition())
		{
			$result->addError(new Error(Loc::getMessage('BIC_SELFHOST_ERROR_EDITION')));
		}

		return $result;
	}

	/**
	 * Whether the switch is allowed at all, without touching anything. Separate from applying it, because the
	 * caller inserts its own step in between: the settings page checks that the local server answers before
	 * the data of the other mode is destroyed.
	 *
	 * A switch to the mode already in use is allowed and changes nothing.
	 */
	public static function checkSwitchMode(string $newMode): Result
	{
		$result = new Result();
		if ($newMode === self::getMode())
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

		if ($newMode === self::MODE_SELFHOSTED)
		{
			$restrictionResult = self::checkSelfHostedRestrictions();
			if (!$restrictionResult->isSuccess())
			{
				Logger::logWarning($restrictionResult->getErrors(), [
					'message' => 'Switching to the self-hosted Superset mode is restricted',
				]);

				return $restrictionResult;
			}
		}

		return $result;
	}

	/**
	 * Applies the switch: the data of the mode being left is destroyed, so this runs only after every check of
	 * the caller has passed. Not idempotent and not a check: call it once, on a verified switch.
	 *
	 * On the way back to the cloud the mode change is handed over to the reset of the license state of the instance,
	 * which applies it under the lock of the delivery: without a confirmed reset nothing is applied and the switch
	 * answers with a refusal.
	 */
	public static function applySwitchMode(string $newMode): Result
	{
		$result = new Result();

		if ($newMode === self::MODE_CLOUD && self::isSelfHosted())
		{
			// The local integrator is asked before anything is destroyed and before the mode changes: the factory
			// answers by the current mode, so after the switch the request would go to the cloud integrator and be
			// refused by the portal itself.
			$resetResult = SelfHostedLicenseSync::resetInstanceLicenseState(
				static function () use ($newMode): void {
					self::applyModeChange($newMode);
				},
			);
			if (!$resetResult->isSuccess())
			{
				Logger::logWarning($resetResult->getErrors(), [
					'message' => 'Switching back to the cloud Superset mode is refused: the instance is not reset',
				]);
				$result->addError(new Error(Loc::getMessage('BIC_SELFHOST_ERROR_LICENSE_RESET')));
			}

			return $result;
		}

		self::applyModeChange($newMode);

		return $result;
	}

	/**
	 * Destroys the data of the mode being left and switches over. On the way back to the cloud it is never called
	 * from here: there it runs inside the reset of the license state of the instance, which holds the lock of the
	 * delivery around it until the mode has changed and the agent is out of the schedule.
	 */
	private static function applyModeChange(string $newMode): void
	{
		SupersetInitializer::clearSupersetData();
		SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);
		AccessInstaller::install();

		if ($newMode === self::MODE_CLOUD && Loader::includeModule('superset'))
		{
			// Neither an absent registration of the local server nor a refusal to delete it stops the switch: the data
			// of the mode being left is already destroyed here, so a switch that stopped would leave the portal in
			// neither mode. A record left behind grants nothing: it is consulted by the mode the portal is leaving.
			$disconnectResult = (new SelfHostedConnectionService())->disconnectServer();
			if (!$disconnectResult->isSuccess())
			{
				Logger::logWarning($disconnectResult->getErrors(), [
					'message' => 'The record of the self-hosted Superset server is left behind:'
						. ' the switch to the cloud mode is finished anyway',
				]);
			}
		}

		self::setMode($newMode);
		IntegratorFactory::reset();

		if ($newMode === self::MODE_SELFHOSTED)
		{
			// Both agents of the license serve the local mode only, so the switch is what puts them into the
			// schedule: a cloud portal would carry agents with nothing to do, and a registration repeated by every
			// update is what multiplies their rows. An update installs them only for a portal already switched into
			// the local mode, which this call can no longer reach.
			SelfHostedLicenseSync::installAgent();
			SelfHostedLicenseNotifier::installAgent();

			// The delivery of the license term keeps silent outside the local mode, and the server is connected
			// before the switch is applied, so the moment the mode changes is the moment it becomes possible.
			SelfHostedLicenseSync::scheduleDeliveryOnConnect();
		}
		else
		{
			SelfHostedLicenseSync::removeAgent();
			SelfHostedLicenseNotifier::removeAgent();
		}
	}

	/**
	 * Conditions of the switch to the self-hosted mode, checked in the order of importance: a wrong edition
	 * makes buying the extension pointless, so it is reported first.
	 *
	 * Public so that the settings page does not offer a switch that would be refused. The refusal itself stays
	 * on the action: the page only mirrors the verdict.
	 */
	public static function checkSelfHostedRestrictions(): Result
	{
		$result = self::checkSelfHostedRegion();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = self::checkSelfHostedEdition();
		if (!$result->isSuccess())
		{
			return $result;
		}

		// A term inside its grace still allows the switch: the work is allowed until the grace runs out, and a
		// portal renewing the license in those days should not be kept out of the mode it has paid for.
		$licenseStates = [SelfHostedLicenseState::Active, SelfHostedLicenseState::Grace];
		if (!in_array(SelfHostedLicense::getInstance()->getState(), $licenseStates, true))
		{
			$result->addError(new Error(Loc::getMessage('BIC_SELFHOST_ERROR_EXTENSION')));
		}

		return $result;
	}
}
