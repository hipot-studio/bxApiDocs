<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Strategy;

use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\Portal;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\TrialExpireDate;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\VibePlusFeatureSet;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\ActivationStrategy;

final class VibePlusFeatureTrialStrategy implements ActivationStrategy
{
	private bool $granted = false;

	private readonly Portal $portal;

	public function __construct(?Portal $portal = null)
	{
		$this->portal = $portal ?? new Portal();
	}

	public function isApplicable(): bool
	{
		return $this->portal->isCloudPortal()
			&& ($this->portal->isPaidLicense() || $this->portal->isNfrLicense());
	}

	public function isActivated(): bool
	{
		return VibePlusFeatureSet::hasFullAccess();
	}

	public function findExpireDate(): ?DateTime
	{
		$earliest = null;
		foreach (VibePlusFeatureSet::FEATURE_IDS as $featureId)
		{
			$expireDate = TrialExpireDate::forFeature($featureId);
			if ($expireDate !== null && ($earliest === null || $expireDate->getTimestamp() < $earliest->getTimestamp()))
			{
				$earliest = $expireDate;
			}
		}

		return $earliest;
	}

	public function activate(int $days): void
	{
		$featureIds = VibePlusFeatureSet::getDisabledFeatureIds();
		$expireDates = VibePlusFeatureSet::getTrialExpireDates();

		try
		{
			$this->granted = $this->portal->grantFeatures(VibePlusFeatureSet::FEATURE_IDS, $days);
		}
		catch (\Throwable $exception)
		{
			$this->markGrantedFeatures($featureIds, $expireDates);

			throw $exception;
		}
	}

	public function hasGrantedTrial(): bool
	{
		return $this->granted;
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
}
