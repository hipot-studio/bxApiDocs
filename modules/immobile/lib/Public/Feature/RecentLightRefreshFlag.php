<?php

declare(strict_types=1);

namespace Bitrix\ImMobile\Public\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Loader;

/**
 * Switch of the light refresh of the chat list: the client asks for the light fragments instead of
 * the hydrated first page. On by default - the flag is the way back, not the way in.
 *
 * im and immobile ship apart, so whether im answers the fragments at all is a requirement and not
 * the default: requirements are checked before the rules and the stored value, so an explicit
 * enable cannot open the light mode against an im that lacks the marker of the contract.
 */
final class RecentLightRefreshFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return true;
	}

	public function getRequirements(): array
	{
		return [
			static fn (): bool => Loader::includeModule('im')
				&& defined(\Bitrix\Im\Recent::class . '::SHAPE_OPTION'),
		];
	}
}
