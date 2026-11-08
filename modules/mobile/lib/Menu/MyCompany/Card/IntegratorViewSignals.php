<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Card;

/**
 * Visible view of the integrator tile: whether a tap leads anywhere and whether the menu has an item
 * for this user. Decided here so the heavy half of the payload is asked for on the tap alone. Pure
 * rules over the same payload shape the card keeps (`more-menu/block/company/integrator/src/view-model`).
 */
final class IntegratorViewSignals
{
	/**
	 * A partner that arrived with nothing but a name would open an empty sheet, so such a tile stays
	 * informational - no button, no tap on the body.
	 */
	public static function canContact(array $integrator): bool
	{
		return !empty($integrator['email'])
			|| !empty($integrator['phone'])
			|| !empty($integrator['cardUrl'])
			|| !empty($integrator['partnerFormId'])
			|| self::isExternalSite((string)($integrator['url'] ?? ''))
		;
	}

	/** Deny by default: no manage right, no items; and every item needs the data it acts upon. */
	public static function hasMenuActions(array $integrator): bool
	{
		if (empty($integrator['canManage']))
		{
			return false;
		}

		return !empty($integrator['feedbackFormId'])
			|| (!empty($integrator['refusalFormId']) && !empty($integrator['deleteAction']))
		;
	}

	/**
	 * The value arrives from the partner profile through the license server, so it is not ours to
	 * trust: a relative path or a local scheme (`b24:`) is no site and must not make the tile look
	 * tappable. A site written without a scheme still counts - the card prepends one.
	 */
	private static function isExternalSite(string $url): bool
	{
		if ($url === '')
		{
			return false;
		}

		if (preg_match('#^(https?:)?//#i', $url) === 1)
		{
			return true;
		}

		return !str_starts_with($url, '/') && preg_match('#^[a-z][a-z\d+.\-]*:#i', $url) !== 1;
	}
}
