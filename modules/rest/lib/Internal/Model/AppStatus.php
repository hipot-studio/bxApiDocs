<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Rest\Internal\Entity\Application\AppOrigin;
use Bitrix\Rest\Internal\Entity\Application\AppPricingModel;
use Bitrix\Rest\Internal\Entity\Application\AppLicenseState;

enum AppStatus: string
{
	case Local = 'L';
	case Free = 'F';
	case Paid = 'P';
	case Demo = 'D';
	case Trial = 'T';
	case Subscription = 'S';

	public function getOrigin(): AppOrigin
	{
		return match ($this) {
			self::Local => AppOrigin::Local,
			default => AppOrigin::Marketplace,
		};
	}

	public function getPricingModel(): ?AppPricingModel
	{
		return match ($this) {
			self::Free => AppPricingModel::Free,
			self::Paid, self::Trial, self::Demo => AppPricingModel::Paid,
			self::Subscription => AppPricingModel::Subscription,
			self::Local => null,
		};
	}

	public function getLicenseState(): ?AppLicenseState
	{
		return match ($this) {
			self::Paid, self::Subscription => AppLicenseState::Active,
			self::Trial => AppLicenseState::Trial,
			self::Demo => AppLicenseState::Demo,
			self::Free, self::Local => null,
		};
	}

	public static function fromComponents(
		AppOrigin $origin,
		?AppPricingModel $pricingModel = null,
		?AppLicenseState $licenseState = null,
	): self
	{
		if ($origin === AppOrigin::Local)
		{
			return self::Local;
		}

		if ($pricingModel === AppPricingModel::Free)
		{
			return self::Free;
		}

		if ($licenseState === AppLicenseState::Trial)
		{
			return self::Trial;
		}

		if ($licenseState === AppLicenseState::Demo)
		{
			return self::Demo;
		}

		if ($pricingModel === AppPricingModel::Subscription)
		{
			return self::Subscription;
		}

		return self::Paid;
	}
}
