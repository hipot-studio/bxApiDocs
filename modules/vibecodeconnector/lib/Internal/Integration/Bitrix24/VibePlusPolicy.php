<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Feature;
use Bitrix\Bitrix24\Public\Service\VibePlus\RuntimeStateProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class VibePlusPolicy
{
	private const VIBECODE_FEATURE = 'vibecode';
	private const REST_ACCESS_TRANSITION_PERIOD_FEATURE = 'rest_access_transition_period';

	private ?RuntimeStateProvider $runtimeStateProvider;

	public function __construct(
		?RuntimeStateProvider $runtimeStateProvider = null,
		?bool $isModuleIncluded = null,
	)
	{
		$isModuleIncluded ??= Loader::includeModule('bitrix24');
		$this->runtimeStateProvider = $isModuleIncluded
			? ($runtimeStateProvider ?? $this->resolveRuntimeStateProvider())
			: null;
	}

	public function getAvailability(): ?bool
	{
		if (
			$this->runtimeStateProvider === null
			|| !$this->runtimeStateProvider->hasVibePlusMonetizationModel()
			|| !$this->runtimeStateProvider->isLaunchDateReached()
		)
		{
			return null;
		}

		if (
			$this->runtimeStateProvider->hasVibePlusMonetizationModel()
			&& !$this->runtimeStateProvider->isVibePlusStartEnabled()
		)
		{
			return null;
		}

		if (
			$this->isFeatureEnabled(self::VIBECODE_FEATURE)
			&& $this->runtimeStateProvider->isCurrentEditionActive()
		)
		{
			return true;
		}

		if (
			$this->runtimeStateProvider->isTransitionPeriodActive()
			&& $this->isFeatureEnabled(self::REST_ACCESS_TRANSITION_PERIOD_FEATURE)
			&& $this->runtimeStateProvider->isCurrentEditionActive()
		)
		{
			return true;
		}

		if (!$this->runtimeStateProvider->isTransitionPeriodInitialized())
		{
			return $this->hasPaidTariffAccess()
				&& $this->runtimeStateProvider->isCurrentEditionActive();
		}

		return false;
	}

	protected function isFeatureEnabled(string $feature): bool
	{
		return Feature::isFeatureChargeable($feature) && Feature::isFeatureEnabled($feature);
	}

	protected function hasPaidTariffAccess(): bool
	{
		return (new PaidTariff())->isCloudLicensePaid();
	}

	private function resolveRuntimeStateProvider(): ?RuntimeStateProvider
	{
		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has(RuntimeStateProvider::class))
		{
			return null;
		}

		return $serviceLocator->get(RuntimeStateProvider::class);
	}
}
