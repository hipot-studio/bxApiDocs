<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Facade;

use Bitrix\Landing\Manager;
use Bitrix\Main\Loader;
use Bitrix\Bitrix24\Feature;
use Bitrix\Intranet;

class Portal
{
	public const VIBE_FEATURE = 'main_page';
	private const FREE_TARIFF_OPTION_CODE = 'vibe_free_tariff_mode';

	private bool $isBitrix24;
	private bool $isIntranet;

	public function __construct()
	{
		try
		{
			$this->isBitrix24 = Loader::includeModule('bitrix24');
			$this->isIntranet = Loader::includeModule('intranet');
		}
		catch (\Exception $e)
		{
			$this->isBitrix24 = false;
			$this->isIntranet = false;
		}
	}

	public function isCloud(): bool
	{
		return $this->isBitrix24;
	}

	public function isIntranet(): bool
	{
		return $this->isIntranet;
	}

	public function checkFeature(string $feature, ?string $for = null): bool
	{
		if (!$this->isCloud())
		{
			return true;
		}

		if (isset($for))
		{
			return Feature::isFeatureEnabledFor($feature, $for);
		}

		return Feature::isFeatureEnabled($feature);
	}

	/**
	 * Portal wide permission to create and edit vibes while the tariff has no vibe feature
	 * (demo vibes installed from presets). Viewing does not depend on it.
	 * @return bool
	 */
	public function isFreeTariffMode(): bool
	{
		return Manager::getOption(self::FREE_TARIFF_OPTION_CODE, 'N') === 'Y';
	}

	public function setFreeTariffMode(bool $flag): void
	{
		if ($flag === $this->isFreeTariffMode())
		{
			return;
		}

		Manager::setOption(self::FREE_TARIFF_OPTION_CODE, $flag ? 'Y' : 'N');
	}

	/**
	 * Creating and editing of vibes is allowed by the vibe feature or by the free tariff mode.
	 * @return bool
	 */
	public function isVibeFeatureAvailable(): bool
	{
		return $this->checkFeature(self::VIBE_FEATURE) || $this->isFreeTariffMode();
	}

	public function isIntranetUser(): bool
	{
		return $this->isIntranet && (new Intranet\User())->isIntranet();
	}

	public function isIntranetAdmin(): bool
	{
		return
			$this->isIntranetUser()
			&& Intranet\CurrentUser::get()->isAdmin();
	}

	public function isExtranetUser(): bool
	{
		return
			$this->isIntranet
			&& $this->isBitrix24
			&& \CBitrix24::IsExtranetUser(Intranet\CurrentUser::get()->getId());
	}
}