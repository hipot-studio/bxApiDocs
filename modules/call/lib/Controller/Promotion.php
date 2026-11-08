<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Controller\ActionFilter\DenyGuest;
use Bitrix\Call\Promotion\SyncTabBannerPromo;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\EnablePrefilters;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\Scope;
use Bitrix\Main\Engine\Controller;

/**
 * @internal
 */
class Promotion extends Controller
{
	/**
	 * Takes the sync tab banner show for the current user: re-checks the right to show it and
	 * marks the promo as seen in the same call, so two tabs cannot both get the permission.
	 *
	 * Response shape:
	 * ```
	 * ['allowed' => bool] // true when this call took the show, false when it was already taken
	 * ```
	 */
	// The ajax transport does not ask who the caller is, unlike the REST guest allow-list this
	// promo never goes through - the denial has to sit on the action itself (@see DenyGuest).
	#[Scope(ActionFilter\Scope::AJAX)]
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	#[EnablePrefilters([new DenyGuest()])]
	public function claimSyncTabBannerAction(): array
	{
		return ['allowed' => SyncTabBannerPromo::claim()];
	}
}
