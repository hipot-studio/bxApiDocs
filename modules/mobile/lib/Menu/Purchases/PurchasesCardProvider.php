<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Purchases;

use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\PurchasesFeature;
use Bitrix\Mobile\Internal\Integration\Bitrix24\License;
use Bitrix\Mobile\Provider\CommonUserDto;

final class PurchasesCardProvider
{
	public function __construct(private ?License $license = null)
	{
	}

	/**
	 * Whether the "purchases and invoices" card of the company block is available.
	 * The card carries no data, so this flag is the whole server contract.
	 *
	 * The permission term is read from CurrentUser inside License, so $currentUser
	 * must be their DTO and no one else's.
	 *
	 * Terms are ordered cheapest first: the local feature flag and the user type
	 * short-circuit before any call into bitrix24. Any failure hides the card - the
	 * flag is computed on every menu request, so a throw here would break the
	 * response for every user of the portal.
	 */
	public function isVisibleForCurrentUser(CommonUserDto $currentUser): bool
	{
		try
		{
			return
				Feature::isEnabled(PurchasesFeature::class)
				&& !$currentUser->isExtranet
				&& !$currentUser->isCollaber
				&& !$currentUser->isGuest
				&& $this->getLicense()->isCloud()
				&& $this->getLicense()->currentUserCanAccessPurchases()
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function getLicense(): License
	{
		return $this->license ??= new License();
	}
}
