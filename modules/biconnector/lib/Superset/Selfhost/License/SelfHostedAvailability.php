<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Application;

/**
 * The only answer to the question what to show to a user who opened the BI builder.
 *
 * Gates run strictly in order and the chain stops at the first one that fails, so the notification priority
 * follows from the order instead of comparing conditions. Every gate asks about the licensing of the local mode
 * and none of them goes to the network: the answer is read from the portal database only.
 */
class SelfHostedAvailability
{
	private static ?self $instance = null;

	private ?SelfHostedAvailabilityState $state = null;

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public static function reset(): void
	{
		self::$instance = null;
	}

	public function getState(): SelfHostedAvailabilityState
	{
		$this->state ??= $this->resolveState();

		return $this->state;
	}

	/**
	 * States that leave the work running. Every other state of the chain closes it, so a state added to the chain
	 * blocks the work until it is listed here on purpose.
	 */
	private const ALLOWED_STATES = [
		SelfHostedAvailabilityState::Available,
		SelfHostedAvailabilityState::ExtensionGrace,
	];

	/**
	 * Whether the local mode is closed for work right now: every barrier asks this and not a single gate of the
	 * chain, so a state the chain refuses on cannot stay open in one place while it is closed in another.
	 *
	 * Outside the local mode the chain answers "available", so the barriers need no check of the mode of their own.
	 */
	public function isBlocked(): bool
	{
		return !in_array($this->getState(), self::ALLOWED_STATES, true);
	}

	private function resolveState(): SelfHostedAvailabilityState
	{
		if (!SupersetHostMode::isSelfHosted())
		{
			return SelfHostedAvailabilityState::Available;
		}

		if (!SupersetHostMode::checkSelfHostedEdition()->isSuccess())
		{
			return SelfHostedAvailabilityState::TariffUnavailable;
		}

		if ($this->isBoxLicenseExpired())
		{
			return SelfHostedAvailabilityState::BoxLicenseExpired;
		}

		$licenseState = SelfHostedLicense::getInstance()->getState();
		if ($licenseState === SelfHostedLicenseState::None)
		{
			return SelfHostedAvailabilityState::ExtensionMissing;
		}

		if ($licenseState === SelfHostedLicenseState::Grace)
		{
			return SelfHostedAvailabilityState::ExtensionGrace;
		}

		if ($licenseState === SelfHostedLicenseState::Expired)
		{
			return SelfHostedAvailabilityState::ExtensionExpired;
		}

		return SelfHostedAvailabilityState::Available;
	}

	/**
	 * A time-bound edition without an expiry date counts as expired: the licensing layer always fills the
	 * date for such editions.
	 *
	 * Public because the settings page reports this state whatever the host mode is: the gate chain answers
	 * "available" outside the local mode, while an expired box license is a reason to say something anyway.
	 */
	public function isBoxLicenseExpired(): bool
	{
		if (!Application::getInstance()->getLicense()->isTimeBound())
		{
			return false;
		}

		// The same term the instance is given, grace included: the product itself closes access fifteen days
		// after the paid term, and two answers to one question would leave the portal and the instance blocking
		// the reports on different days.
		$term = BoxLicenseTerm::getExpiryTimestamp();

		return $term === null || $term < time();
	}
}
