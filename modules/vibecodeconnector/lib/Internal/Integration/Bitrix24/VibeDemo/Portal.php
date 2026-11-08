<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo;

use Bitrix\Bitrix24\License;
use Bitrix\Bitrix24\Public\Command\Licensing\VibePlus\ActivateDemoTariffCommand;
use Bitrix\Bitrix24\Public\Command\Licensing\VibePlus\ActivateTrialFeaturesCommand;
use Bitrix\Bitrix24\Public\Service\VibePlus\RuntimeStateProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Exception\ActivationFailedException;

class Portal
{
	private const FREE_LICENSE_TYPE = 'project';

	public function isCloudPortal(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	public function isFreeLicense(): bool
	{
		return (string)\CBitrix24::getLicenseType() === self::FREE_LICENSE_TYPE;
	}

	public function isPaidLicense(): bool
	{
		return \CBitrix24::isLicensePaid();
	}

	public function isNfrLicense(): bool
	{
		return \CBitrix24::IsNfrLicense();
	}

	public function isDemoAvailable(): bool
	{
		return License::getCurrent()->getDemo()->isAvailable();
	}

	public function isDemoActive(): bool
	{
		return License::getCurrent()->getDemo()->isActive();
	}

	public function activateDemoIfAvailable(): bool
	{
		try
		{
			if ($this->isDemoActive() || !$this->isDemoAvailable())
			{
				return false;
			}

			return $this->activateDemo();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getRegion(): string
	{
		return (string)\CBitrix24::getPortalZone();
	}

	public function isVibePlusTariffLineAvailable(): bool
	{
		return $this->isCloudPortal()
			&& $this->findRuntimeStateProvider()?->isVibePlusTariffLineAvailable() === true;
	}

	public function grantTariff(string $tariff, int $days): bool
	{
		return $this->runCommand(static fn(): Result => (new ActivateDemoTariffCommand($tariff, $days))->run());
	}

	public function grantFeatures(array $featureIds, int $days): bool
	{
		$extendUntil = (new DateTime())->setTime(0, 0)->add($days . 'D');

		return $this->runCommand(
			static fn(): Result => (new ActivateTrialFeaturesCommand($featureIds, $days, $extendUntil))->run(),
		);
	}

	protected function activateDemo(): bool
	{
		return License::getCurrent()->getDemo()->activate()->isSuccess();
	}

	private function findRuntimeStateProvider(): ?RuntimeStateProvider
	{
		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has(RuntimeStateProvider::class))
		{
			return null;
		}

		return $serviceLocator->get(RuntimeStateProvider::class);
	}

	private function runCommand(\Closure $command): bool
	{
		try
		{
			$result = $command();
		}
		catch (\Throwable $exception)
		{
			throw ActivationExceptionMapper::map($exception);
		}

		if (!$result->isSuccess())
		{
			throw new ActivationFailedException();
		}

		return (bool)($result->getData()['granted'] ?? false);
	}
}
