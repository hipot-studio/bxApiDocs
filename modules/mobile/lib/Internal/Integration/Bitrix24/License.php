<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24;
use Bitrix\Bitrix24\License\Market;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\License as MainLicense;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Integration\ServiceFactory;
use CBitrix24;

/**
 * Integration layer for all bitrix24/market domain calls.
 * All other classes in the mobile module must use this class
 * instead of calling CBitrix24, License, License\Market directly.
 */
class License
{
	private ?MainLicense $mainLicense;
	private readonly ServiceFactory $services;

	public function __construct(?MainLicense $mainLicense = null, ?ServiceFactory $services = null)
	{
		$this->mainLicense = $mainLicense;
		$this->services = $services ?? new ServiceFactory();
	}

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

	/**
	 * The moment the portal was created, to the second; null outside the cloud and when the portal
	 * states none.
	 */
	public function getCreatedAt(): ?DateTime
	{
		if (!$this->isCloud())
		{
			return null;
		}

		$timestamp = (int)CBitrix24::getCreateTime();

		return $timestamp > 0 ? DateTime::createFromTimestamp($timestamp) : null;
	}

	/**
	 * The edition of the current license, such as "team"; null outside the cloud and when the license
	 * names none.
	 */
	public function getTariffCode(): ?string
	{
		if (!$this->isCloud())
		{
			return null;
		}

		$code = (string)CBitrix24::getLicenseType();

		return $code === '' ? null : $code;
	}

	/**
	 * The partner of the portal, 0 for none: the one of the cloud license, or of the license of the
	 * installation outside the cloud.
	 */
	public function getPartnerId(): int
	{
		if ($this->isCloud())
		{
			return Bitrix24\License::getCurrent()->getPartnerId();
		}

		return $this->getMainLicense()->getPartnerId();
	}

	/**
	 * The region of the license in both contours.
	 */
	public function getRegion(): ?string
	{
		return $this->getMainLicense()->getRegion();
	}

	private function getMainLicense(): MainLicense
	{
		return $this->mainLicense ??= $this->services->mainLicense();
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
