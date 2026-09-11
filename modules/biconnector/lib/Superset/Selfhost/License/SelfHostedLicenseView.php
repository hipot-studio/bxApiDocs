<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Flat structure of the availability state for client surfaces. The client does not combine conditions:
 * it receives a resolved state together with the texts and shows it.
 */
final class SelfHostedLicenseView
{
	private const DATE_FORMAT = 'd.m.Y';

	private const ACTIONABLE_STATES = [
		SelfHostedAvailabilityState::TariffUnavailable,
		SelfHostedAvailabilityState::BoxLicenseExpired,
		SelfHostedAvailabilityState::ExtensionMissing,
		SelfHostedAvailabilityState::ExtensionGrace,
		SelfHostedAvailabilityState::ExtensionExpired,
	];

	/**
	 * States announced by a banner instead of a page stub that takes over the section.
	 */
	private const NOTICE_STATES = [
		SelfHostedAvailabilityState::TariffUnavailable,
		SelfHostedAvailabilityState::ExtensionMissing,
		SelfHostedAvailabilityState::ExtensionGrace,
		SelfHostedAvailabilityState::ExtensionExpired,
		SelfHostedAvailabilityState::BoxLicenseExpired,
	];

	/**
	 * A term that is over is announced in red, a term that is only running out - in yellow: the first one has
	 * already taken the reports away or is about to, the second one still leaves months to renew. Both terms
	 * count here - of the extension and of the box itself - and a term inside its grace counts too: the work
	 * still runs, but the day it stops is already set.
	 */
	private const ALERT_STATES = [
		SelfHostedAvailabilityState::ExtensionGrace,
		SelfHostedAvailabilityState::ExtensionExpired,
		SelfHostedAvailabilityState::BoxLicenseExpired,
	];

	public const DESIGN_WARNING = 'warning';
	public const DESIGN_ALERT = 'alert';

	public function __construct(
		private readonly SelfHostedAvailability $availability,
		private readonly SelfHostedLicense $license,
		private readonly bool $isAdmin,
	)
	{
	}

	public static function createForCurrentUser(): self
	{
		return new self(
			SelfHostedAvailability::getInstance(),
			SelfHostedLicense::getInstance(),
			AccessController::getCurrent()->getUser()->isAdmin(),
		);
	}

	public function toArray(): array
	{
		$state = $this->availability->getState();
		$expiryDate = $this->license->getExpiryDate();
		$hasAction = $this->isAdmin && in_array($state, self::ACTIONABLE_STATES, true);
		$actionUrl = $hasAction ? $this->getActionUrl($state) : null;

		$blockDate = $this->license->getBlockDate();

		return [
			'state' => $state->value,
			// Told apart from the gate chain state on purpose: outside the local mode the chain reports an
			// available state whatever the extension is, so only this field answers whether it is paid for.
			'licenseState' => $this->license->getState()->value,
			'expiryDate' => $expiryDate?->format(self::DATE_FORMAT),
			'expiryTimestamp' => $expiryDate?->getTimestamp(),
			// The day the work stops, and it is not the day the term ends: the grace after the term keeps the
			// reports running.
			'blockDate' => $blockDate?->format(self::DATE_FORMAT),
			'blockTimestamp' => $blockDate?->getTimestamp(),
			'daysLeft' => $this->getDaysLeft($expiryDate),
			'warningIsDue' => $this->isWarningDue($expiryDate, SelfHostedLicenseNotifier::WARNING_INTERVAL),
			'title' => $state->getTitle(),
			'description' => $state->getDescription(),
			'actionText' => $actionUrl === null ? null : $state->getActionText(),
			'actionUrl' => $actionUrl,
			'isAdmin' => $this->isAdmin,
		];
	}

	/**
	 * Parameters of the banner or null when there is nothing to announce. A state replaced by a page stub is not
	 * a banner: its owner is the section it takes over.
	 */
	public function getNoticeParams(): ?array
	{
		$state = $this->availability->getState();
		if (!in_array($state, self::NOTICE_STATES, true))
		{
			return $this->getExpiryWarningParams($state);
		}

		$actionUrl = $this->isAdmin ? $this->getActionUrl($state) : null;
		// The only state whose texts are not phrases of its own: the two dates of the grace are known here.
		$graceTexts = $state === SelfHostedAvailabilityState::ExtensionGrace ? $this->getGraceTexts() : null;

		return [
			'state' => $state->value,
			'design' => $this->getDesign($state),
			'title' => $graceTexts['title'] ?? $state->getTitle(),
			'description' => $graceTexts['description'] ?? $state->getDescription(),
			'actionText' => $actionUrl === null ? null : $state->getActionText(),
			// Continuation of the sentence the link starts, so it goes away together with the link.
			'actionNote' => $actionUrl === null ? null : $state->getActionNote(),
			'actionUrl' => $actionUrl,
		];
	}

	/**
	 * Texts of the expiry warning for the surfaces every user sees: the wording of a term running out belongs to
	 * the license, not to the surface that displays it. Null outside the window of the last month.
	 */
	public function getExpiryWarningTexts(): ?array
	{
		return $this->buildExpiryWarningTexts(SelfHostedLicenseNotifier::WARNING_INTERVAL);
	}

	/**
	 * The same texts in a window that opens three months before the end. Administrators are warned that early
	 * because renewing a license takes an order and a payment, not a click; a user of the reports sees the same
	 * warning a month before, when the date is close enough to mean something to them.
	 */
	public function getEarlyExpiryWarningTexts(): ?array
	{
		return $this->buildExpiryWarningTexts(SelfHostedLicenseNotifier::EARLY_WARNING_INTERVAL);
	}

	/**
	 * Parameters of the popup that meets an administrator in the grid of the reports inside the early window, or
	 * null when there is nothing to say. The surface remembers on its own that the popup has been closed, so the
	 * key of the term is handed over with the texts: a renewed term is a new occasion to warn.
	 */
	public function getExpiryPopupParams(): ?array
	{
		$texts = $this->getEarlyExpiryWarningTexts();
		if (
			$texts === null
			|| !$this->isAdmin
			|| !SupersetHostMode::isSelfHosted()
			|| $this->availability->getState() !== SelfHostedAvailabilityState::Available
		)
		{
			return null;
		}

		return [
			'title' => $texts['title'],
			'text' => $texts['description'],
			'termKey' => (string)$this->license->getExpiryDate()?->getTimestamp(),
		];
	}

	/**
	 * Texts of a term that is over while its grace is not: the date that has passed and the date the work stops on.
	 */
	private function getGraceTexts(): array
	{
		return [
			'title' => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_NOTICE_GRACE_TITLE', [
				'#DATE#' => (string)$this->license->getExpiryDate()?->format(self::DATE_FORMAT),
			]),
			'description' => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_NOTICE_GRACE_DESCRIPTION', [
				'#BLOCK_DATE#' => (string)$this->license->getBlockDate()?->format(self::DATE_FORMAT),
			]),
		];
	}

	private function buildExpiryWarningTexts(int $window): ?array
	{
		$expiryDate = $this->license->getExpiryDate();
		if (!$this->isWarningDue($expiryDate, $window))
		{
			return null;
		}

		return [
			'title' => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_NOTICE_WARNING_TITLE', [
				'#DATE#' => (string)$expiryDate?->format(self::DATE_FORMAT),
			]),
			'description' => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_NOTICE_WARNING_DESCRIPTION', [
				'#BLOCK_DATE#' => (string)$this->license->getBlockDate()?->format(self::DATE_FORMAT),
			]),
		];
	}

	/**
	 * The warning belongs to a license that still works, so the state carries no phrases of its own. The host
	 * mode is checked here because the gate chain reports an available state for the cloud mode as well, while
	 * the expiry date is read from an option that the cloud mode never fills.
	 */
	private function getExpiryWarningParams(SelfHostedAvailabilityState $state): ?array
	{
		$texts = $this->getExpiryWarningTexts();
		if (
			$texts === null
			|| $state !== SelfHostedAvailabilityState::Available
			|| !SupersetHostMode::isSelfHosted()
		)
		{
			return null;
		}

		$actionUrl = $this->isAdmin ? LicenseLinks::getExtensionPurchaseUrl() : null;

		return [
			'state' => $state->value,
			'design' => $this->getDesign($state),
			'title' => $texts['title'],
			'description' => $texts['description'],
			'actionText' => $actionUrl === null
				? null
				: (string)Loc::getMessage('BIC_SELFHOST_LICENSE_NOTICE_WARNING_ACTION'),
			'actionNote' => null,
			'actionUrl' => $actionUrl,
		];
	}

	private function getDesign(SelfHostedAvailabilityState $state): string
	{
		return in_array($state, self::ALERT_STATES, true) ? self::DESIGN_ALERT : self::DESIGN_WARNING;
	}

	/**
	 * An unsuitable edition leads where the edition itself is sold, and every other state with a call leads to the
	 * extension request section.
	 */
	private function getActionUrl(SelfHostedAvailabilityState $state): ?string
	{
		// An over license of the box is renewed where the licensing layer of the portal itself points: renewing
		// the extension to a license that is over changes nothing.
		if ($state === SelfHostedAvailabilityState::BoxLicenseExpired)
		{
			return LicenseLinks::getBoxRenewalUrl();
		}

		// The same address the settings page offers for this state: what is missing here is the edition and not the
		// extension. This one is also known outside the CIS area, where there is nowhere to apply for the extension.
		if ($state === SelfHostedAvailabilityState::TariffUnavailable)
		{
			return LicenseLinks::getEnterprisePurchaseUrl();
		}

		return LicenseLinks::getExtensionPurchaseUrl();
	}

	private function getDaysLeft(?DateTime $expiryDate): ?int
	{
		$secondsLeft = $this->getSecondsLeft($expiryDate);

		return $secondsLeft === null ? null : intdiv($secondsLeft, 86400);
	}

	/**
	 * The warning windows are the ones the notifier uses, but unlike the one-time delivery flags in options they
	 * are recomputed on every read: a banner is shown for the whole window, not once.
	 */
	private function isWarningDue(?DateTime $expiryDate, int $window): bool
	{
		$secondsLeft = $this->getSecondsLeft($expiryDate);

		return $secondsLeft !== null && $secondsLeft <= $window;
	}

	/**
	 * Null both when there is no date at all and when it has already passed: an expired extension is reported
	 * by the state, not by a negative countdown.
	 */
	private function getSecondsLeft(?DateTime $expiryDate): ?int
	{
		if ($expiryDate === null)
		{
			return null;
		}

		$secondsLeft = $expiryDate->getTimestamp() - time();

		return $secondsLeft < 0 ? null : $secondsLeft;
	}
}
