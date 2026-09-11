<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

/**
 * Padlock on a dashboard that an over term does not let open, and the reaction to a click on it.
 *
 * The section itself keeps working when the term is over, so every surface offering a dashboard (the grid, the
 * top menus of the zones) asks the same answer here.
 */
final class SelfHostedLicenseLock
{
	/**
	 * Slider of the restriction, registered by the product. Spelled exactly as it is registered there: the slider
	 * is found by this code, so a guessed or corrected one would open nothing.
	 *
	 * One slider for every locked state, so for a license of the box and for an edition without the right to the
	 * mode it names the extension all the same: the reason is told by the banner above the grid.
	 */
	private const SLIDER_CODE = 'limit_v2_bi_selfhost_extension_expired';

	/**
	 * Every state that took the right to the local mode away locks a dashboard the same way: the reason is told by
	 * the banner, not by the padlock. An extension that was never bought is the one state left out - such a portal
	 * is offered to buy it and has no report of its own to lock yet.
	 */
	private const LOCKING_STATES = [
		SelfHostedAvailabilityState::TariffUnavailable,
		SelfHostedAvailabilityState::ExtensionExpired,
		SelfHostedAvailabilityState::BoxLicenseExpired,
	];

	public static function isDashboardLocked(): bool
	{
		return in_array(SelfHostedAvailability::getInstance()->getState(), self::LOCKING_STATES, true);
	}

	public static function getOpenSliderScript(): string
	{
		return "top.BX.UI.InfoHelper.show('" . self::SLIDER_CODE . "')";
	}

	/**
	 * What is said in place of a form or a workplace a finished term does not open. Phrases of the state and not
	 * of the surface: the reason is one and is already worded for the banner.
	 */
	public static function getRestrictionMessage(): string
	{
		$state = SelfHostedAvailability::getInstance()->getState();
		$parts = array_filter([$state->getTitle(), $state->getDescription()], static fn(string $part) => $part !== '');

		return implode(' ', $parts);
	}
}
