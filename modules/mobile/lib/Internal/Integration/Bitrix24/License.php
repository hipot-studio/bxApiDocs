<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24;
use Bitrix\Bitrix24\License\Market;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\DateTime;
use CBitrix24;

/**
 * Integration layer for all bitrix24/market domain calls.
 * All other classes in the mobile module must use this class
 * instead of calling CBitrix24, License, License\Market directly.
 */
final class License
{
	public function isCloud(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return ModuleManager::isModuleInstalled('bitrix24');
	}

	public function isDemo(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return CBitrix24::IsDemoLicense();
	}

	public function isFree(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return CBitrix24::isFreeLicense();
	}

	public function isActive(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return Bitrix24\License::getCurrent()->isActive();
	}

	public function isAutopay(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return Bitrix24\License::getCurrent()->isAutopay();
	}

	public function isDowngradedToFree(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return Bitrix24\License::getCurrent()->isDowngradedToFree();
	}

	public function getDaysLeft(): ?int
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		return Bitrix24\License::getCurrent()->getDaysLeft();
	}

	public function getExpireDate(): ?DateTime
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		return Bitrix24\License::getCurrent()->getExpireDate();
	}

	public function getType(): string
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return '';
		}

		return (string)CBitrix24::getLicenseType();
	}

	public function getFamily(): string
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return '';
		}

		return (string)CBitrix24::getLicenseFamily();
	}

	public function getName(): string
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return '';
		}

		return (string)CBitrix24::getLicenseName();
	}

	/**
	 * Returns subscription state data computed the same way as the web (Market::getData()).
	 *
	 * @return array
	 */
	public function getSubscriptionData(): array
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return [];
		}

		return Market::getData();
	}

	public function getSubscriptionEndDate(): ?DateTime
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		return Market::getEndSubscriptionDate();
	}

	public function isDemoAvailable(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return Bitrix24\License::getCurrent()->getDemo()->isAvailable();
	}

	public function activateDemo(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$demo = Bitrix24\License::getCurrent()->getDemo();

		return $demo->isAvailable() && !$demo->activate()->isSuccess();
	}

	public function currentUserCanManageTariff(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return false;
		}

		return CBitrix24::canAllBuyLicense() || CurrentUser::get()->canDoOperation('bitrix24_config');
	}

	/**
	 * Repeats the gate of the page the card opens (bitrix24/public/settings/order/index.php):
	 * the section is closed only when the option is exactly 'N'. The web license widget compares
	 * the same option with 'Y', which would hide the card from a user the page still lets in.
	 * Deliberately not reusing currentUserCanManageTariff(): CBitrix24::canAllBuyLicense() also
	 * answers true when the tariff has no buy feature at all, which would open the section to a
	 * user who has nothing to see there.
	 */
	public function currentUserCanAccessPurchases(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return false;
		}

		return CurrentUser::get()->canDoOperation('bitrix24_config')
			|| Option::get('bitrix24', 'buy_tariff_by_all', 'Y') !== 'N'
		;
	}
}
