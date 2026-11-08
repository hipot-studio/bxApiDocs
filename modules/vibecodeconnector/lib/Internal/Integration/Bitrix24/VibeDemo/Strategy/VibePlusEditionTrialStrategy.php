<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Portal;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\TrialExpireDate;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\TrialTariffSet;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\VibePlusFeatureSet;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\ActivationStrategy;

final class VibePlusEditionTrialStrategy implements ActivationStrategy
{
	private bool $granted = false;
	private ?TrialTariffSet $tariffSet = null;

	private readonly Portal $portal;

	public function __construct(?Portal $portal = null)
	{
		$this->portal = $portal ?? new Portal();
	}

	public function isApplicable(): bool
	{
		return $this->portal->isCloudPortal()
			&& (
				$this->portal->isFreeLicense()
				|| $this->portal->isDemoAvailable()
				|| (!$this->portal->isPaidLicense() && $this->portal->isDemoActive())
			);
	}

	public function isActivated(): bool
	{
		return $this->findExpireDate() !== null && VibePlusFeatureSet::hasFullAccess();
	}

	public function findExpireDate(): ?DateTime
	{
		$tariff = $this->tariffSet()->findActiveTrialTariff();
		$tariffExpireDate = $tariff === null ? null : TrialExpireDate::forEdition($tariff);
		if ($tariffExpireDate === null)
		{
			return null;
		}

		$featureExpireDate = null;
		foreach (VibePlusFeatureSet::FEATURE_IDS as $featureId)
		{
			if (Feature::isFeaturePermanentlyEnabled($featureId))
			{
				continue;
			}

			$expireDate = TrialExpireDate::forFeature($featureId) ?? $tariffExpireDate;
			if ($featureExpireDate === null || $expireDate < $featureExpireDate)
			{
				$featureExpireDate = $expireDate;
			}
		}

		return $featureExpireDate !== null && $featureExpireDate > $tariffExpireDate
			? $featureExpireDate
			: $tariffExpireDate;
	}

	public function activate(int $days): void
	{
		$this->portal->activateDemoIfAvailable();

		$tariffSet = $this->tariffSet();
		$activeTariff = $tariffSet->findActiveTrialTariff();
		$targetTariff = $activeTariff ?? $tariffSet->getDefaultTariff();

		try
		{
			$grantedEdition = $this->portal->grantTariff($targetTariff, $days);
		}
		catch (\Throwable $exception)
		{
			$this->markGrantedEdition($activeTariff, $targetTariff);

			throw $exception;
		}

		if ($grantedEdition)
		{
			$this->granted = true;
		}

		$this->activateFeatures($days);
	}

	public function hasGrantedTrial(): bool
	{
		return $this->granted;
	}

	private function activateFeatures(int $days): void
	{
		$disabledFeatureIds = VibePlusFeatureSet::getDisabledFeatureIds();
		$expireDates = VibePlusFeatureSet::getTrialExpireDates();

		try
		{
			$grantedFeatures = $this->portal->grantFeatures(VibePlusFeatureSet::FEATURE_IDS, $days);
		}
		catch (\Throwable $exception)
		{
			$this->markGrantedFeatures($disabledFeatureIds, $expireDates);

			throw $exception;
		}

		if ($grantedFeatures)
		{
			$this->granted = true;
		}
	}

	private function markGrantedEdition(?string $activeTariffBeforeActivation, string $targetTariff): void
	{
		if ($activeTariffBeforeActivation === null && TrialExpireDate::forEdition($targetTariff) !== null)
		{
			$this->granted = true;
		}
	}

	private function markGrantedFeatures(array $featureIdsBeforeActivation, array $expireDatesBeforeActivation): void
	{
		if (
			VibePlusFeatureSet::getDisabledFeatureIds() !== $featureIdsBeforeActivation
			|| VibePlusFeatureSet::getTrialExpireDates() !== $expireDatesBeforeActivation
		)
		{
			$this->granted = true;
		}
	}

	private function tariffSet(): TrialTariffSet
	{
		return $this->tariffSet ??= TrialTariffSet::forPortal($this->portal);
	}
}
