<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Intranet\Internal\Integration\Bitrix24\License\DemoLicense;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\InvitationLimiter;
use Bitrix\Intranet\Internal\Integration\Market\SubscriptionTrialProvider;
use Bitrix\Intranet\Internal\Integration\Rest\MarketSubscriptionProvider;
use Bitrix\Intranet\Invitation;
use Bitrix\Intranet\User;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class IntranetInvitationNotification extends CBitrixComponent
{
	private const DEMO_POPUP_VARIANTS = ['variant_a', 'variant_b'];

	public function executeComponent(): void
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return;
		}

		$this->onPrepareComponentResult();

		$this->includeComponentTemplate();
	}

	private function onPrepareComponentResult(): void
	{
		$this->arResult['SHOW_DEMO_POPUP'] =
			\CUserOptions::GetOption('intranet.invitation', 'demoPopupShown', 'N') === 'N'
			&& (new DemoLicense())->isActive()
		;
		$this->arResult['DEMO_POPUP_VARIANT'] = $this->arResult['SHOW_DEMO_POPUP']
			? $this->getDemoPopupVariant()
			: null
		;
		$this->arResult['CAN_INVITE'] =
			Invitation::canCurrentUserInvite()
			&& (new User())->isIntranet()
			&& !(new InvitationLimiter())->isExceeded()
		;
	}

	protected function getDemoPopupVariant(): ?string
	{
		$variant = Option::get('intranet', 'demoInvitePopupVariant', '');
		if (!in_array($variant, self::DEMO_POPUP_VARIANTS, true))
		{
			return null;
		}

		if (
			$variant === 'variant_a'
			&& !$this->isSubscriptionAvailable()
		)
		{
			return null;
		}

		if (
			$variant === 'variant_b'
			&& !$this->isSubscriptionTrialAvailable()
		)
		{
			return null;
		}

		return $variant;
	}

	protected function isSubscriptionAvailable(): bool
	{
		return (new MarketSubscriptionProvider())->isAvailable();
	}

	protected function isSubscriptionTrialAvailable(): bool
	{
		return (new SubscriptionTrialProvider())->isAvailable();
	}
}

