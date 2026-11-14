<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Provider;

use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Service\Environment;
use Bitrix\Disk\Public\Enum\RestrictionPolicy;
use Bitrix\Disk\Public\Enum\RestrictionsAvailabilityState;
use Bitrix\Disk\Public\Features\DiskRestrictions;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\License;

class RestrictionsAvailabilityProvider
{
	protected const TARIFF_FEATURE_CODE = 'disk_restrictions';
	protected const TARGET_EDITION_CODES = ['Enterprise', 'Holding'];

	/** @var array<string, RestrictionsAvailabilityState> state of a policy by its wire value */
	protected array $states = [];

	/**
	 * @param Environment $environment
	 * @param License $boxLicense
	 */
	public function __construct(
		protected readonly Environment $environment,
		protected readonly License $boxLicense,
	)
	{
	}

	/**
	 * Returns whether this restriction of Disk is applied on the portal and, if it is not,
	 * what closes it. The state is an application switch only: settings made earlier
	 * stay untouched while the restriction is unavailable.
	 *
	 * The answer is asked for and kept per policy, so a policy that comes to depend on something
	 * of its own is told apart from the rest without moving the question.
	 *
	 * @param RestrictionPolicy $policy Restriction being asked about.
	 * @return RestrictionsAvailabilityState
	 */
	public function getState(RestrictionPolicy $policy): RestrictionsAvailabilityState
	{
		return $this->states[$policy->value] ??= $this->resolveState($policy);
	}

	/**
	 * Tells if this restriction of Disk is applied on the portal.
	 *
	 * @param RestrictionPolicy $policy Restriction being asked about.
	 * @return bool
	 */
	public function isApplied(RestrictionPolicy $policy): bool
	{
		return $this->getState($policy) === RestrictionsAvailabilityState::Applied;
	}

	protected function resolveState(RestrictionPolicy $policy): RestrictionsAvailabilityState
	{
		if (!$this->isEnabledByFlag())
		{
			return RestrictionsAvailabilityState::DisabledByFlag;
		}

		if ($this->environment->isCloudPortal())
		{
			return $this->isAvailableByTariff()
				? RestrictionsAvailabilityState::Applied
				: RestrictionsAvailabilityState::TariffRestricted
			;
		}

		return $this->isAvailableByEdition()
			? RestrictionsAvailabilityState::Applied
			: RestrictionsAvailabilityState::EditionRestricted
		;
	}

	/**
	 * One flag stands for every restriction of Disk: a rollback takes the whole feature off the
	 * portal rather than one policy of it.
	 */
	protected function isEnabledByFlag(): bool
	{
		return Feature::isEnabled(DiskRestrictions::class);
	}

	protected function isAvailableByTariff(): bool
	{
		return $this->isDeclaredInTariffMap() && $this->isEnabledByTariff();
	}

	/**
	 * A code the tariff map of the cloud does not know is answered as free for everyone, so it is
	 * kept closed until the map declares it: an update of disk arriving before the update of
	 * bitrix24 would otherwise open an Enterprise feature on every tariff.
	 */
	protected function isDeclaredInTariffMap(): bool
	{
		return Bitrix24Manager::isFeatureChargeable(static::TARIFF_FEATURE_CODE);
	}

	protected function isEnabledByTariff(): bool
	{
		return (bool)Bitrix24Manager::isFeatureEnabled(static::TARIFF_FEATURE_CODE);
	}

	protected function isAvailableByEdition(): bool
	{
		return (bool)array_intersect(static::TARGET_EDITION_CODES, $this->boxLicense->getCodes());
	}
}
