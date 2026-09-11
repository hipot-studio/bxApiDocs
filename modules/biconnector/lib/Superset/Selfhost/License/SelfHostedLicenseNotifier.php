<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

use Bitrix\BIConnector\Integration\Superset\Agent;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Personal notifications to administrators about the term of the license extension: the approaching end of
 * the term and the work restored by a renewal.
 *
 * The agent neither blocks anything nor changes the license state: it only records that the warning moment
 * has come and that the term has run out. What to show is decided by the availability chain on every read,
 * and the recorded flags are read and reset when a new date arrives.
 */
class SelfHostedLicenseNotifier
{
	/**
	 * Length of the warning window before the expiry date. Public because client surfaces show the warning
	 * for the same window and the threshold must have a single definition.
	 */
	public const WARNING_INTERVAL = 30 * 86400;

	/**
	 * The window administrators are warned in, three months counted as ninety days. They are warned that early
	 * because a renewal of a boxed license goes through an order and a payment, and a month is not always enough
	 * for it to be paid.
	 */
	public const EARLY_WARNING_INTERVAL = 90 * 86400;

	private const DATE_FORMAT = 'd.m.Y';

	/**
	 * The scheduler rewrites the agent name with the returned value, so it must repeat the registered name
	 * exactly: a different spelling of the same call would let the next migration add a second agent. Public and
	 * without a leading backslash for the same reason as the name of the retry agent - the update of the module
	 * registers the agent by this very constant, and the platform builds the name without the backslash.
	 */
	public const AGENT_NAME =
		'Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseNotifier::notifyExpiringLicense();';

	public const AGENT_INTERVAL = 86400;

	/**
	 * Outside the local mode the agent leaves the schedule the same way the retry agent does: there is nothing to
	 * warn about, and the mode is switched back into the local one by a call that puts both agents back.
	 */
	public static function notifyExpiringLicense(): string
	{
		if (!SupersetHostMode::isSelfHosted())
		{
			return '';
		}

		(new static())->warnAboutExpiringLicense();

		return self::AGENT_NAME;
	}

	public static function installAgent(): void
	{
		AgentInstaller::install(self::AGENT_NAME, self::AGENT_INTERVAL);
	}

	public static function removeAgent(): void
	{
		AgentInstaller::remove(self::AGENT_NAME);
	}

	public function warnAboutExpiringLicense(): void
	{
		if (!SupersetHostMode::isSelfHosted())
		{
			return;
		}

		$expiryDate = $this->readExpiryDate();
		if ($expiryDate === null)
		{
			return;
		}

		$secondsLeft = $expiryDate->getTimestamp() - time();
		if ($secondsLeft < 0)
		{
			// A term that has run out is recorded and nothing else: the stop is announced by the state of the
			// section, while this record is what later tells a renewal from a restoration of stopped work. Recorded
			// on the day the work really stops and not on the day the term ends - the grace between the two keeps
			// the reports running, and there is nothing to restore yet.
			if (SelfHostedLicense::getInstance()->getState() === SelfHostedLicenseState::Expired)
			{
				$this->markWorkStopped();
			}

			return;
		}

		// The window of the last month first: a term that appears already inside it - a license activated shortly
		// before its end - is worth one warning and not two, so the earlier one is written off as shown.
		if ($secondsLeft <= self::WARNING_INTERVAL)
		{
			if ($this->isWarningShown())
			{
				return;
			}

			$this->markWarningShown();
			$this->markEarlyWarningShown();
			$this->notifyAdmins($expiryDate);

			return;
		}

		if ($secondsLeft > self::EARLY_WARNING_INTERVAL || $this->isEarlyWarningShown())
		{
			return;
		}

		$this->markEarlyWarningShown();
		$this->notifyAdmins($expiryDate);
	}

	/**
	 * Confirmation that the work of the BI builder has resumed. Sent only when the work had really stopped:
	 * a renewal made in time interrupts nothing, and there is nothing to announce as restored.
	 */
	public function notifyAboutRestoredWork(): void
	{
		if (!SupersetHostMode::isSelfHosted() || !$this->isWorkStopped())
		{
			return;
		}

		$expiryDate = $this->readExpiryDate();
		if ($expiryDate === null || $expiryDate->getTimestamp() < time())
		{
			return;
		}

		$this->forgetStoppedWork();
		$this->notifyAdminsAboutRestoredWork($expiryDate);
	}

	/**
	 * The license state is re-read because it is remembered in a static instance and an agent lives long
	 * enough for the remembered value to go stale.
	 */
	private function readExpiryDate(): ?DateTime
	{
		SelfHostedLicense::reset();

		return SelfHostedLicense::getInstance()->getExpiryDate();
	}

	/**
	 * Only administrators are notified in a personal channel: the renewal request is theirs to submit, and
	 * a dashboard user gets the same warning as a banner without a call to action.
	 */
	protected function notifyAdmins(DateTime $expiryDate): void
	{
		$formattedDate = $expiryDate->format(self::DATE_FORMAT);
		$formattedBlockDate = (string)SelfHostedLicense::getInstance()->getBlockDate()?->format(self::DATE_FORMAT);
		$renewalLink = LicenseLinks::getExtensionPurchaseUrl();

		$titleCallback = static fn(?string $languageId = null) => Loc::getMessage(
			'BIC_SELFHOST_LICENSE_EXPIRY_NOTIFY_TITLE',
			language: $languageId,
		);
		$messageCallback = static function (?string $languageId = null) use (
			$formattedDate,
			$formattedBlockDate,
			$renewalLink,
		): string {
			$message = (string)Loc::getMessage(
				'BIC_SELFHOST_LICENSE_EXPIRY_NOTIFY_TEXT',
				[
					'#DATE#' => $formattedDate,
					'#BLOCK_DATE#' => $formattedBlockDate,
				],
				$languageId,
			);
			if ($renewalLink === null)
			{
				return $message;
			}

			$action = (string)Loc::getMessage(
				'BIC_SELFHOST_LICENSE_EXPIRY_NOTIFY_ACTION',
				language: $languageId,
			);

			return $message . "\n" . '[URL=' . $renewalLink . ']' . $action . '[/URL]';
		};

		Agent::notifyAllAdmins($titleCallback, $messageCallback);
	}

	/**
	 * Confirmation of the restored work goes to the same recipients as the warning: the renewal is theirs to
	 * submit, and it is they who wait for the answer whether it has arrived.
	 */
	protected function notifyAdminsAboutRestoredWork(DateTime $expiryDate): void
	{
		$formattedDate = $expiryDate->format(self::DATE_FORMAT);

		$titleCallback = static fn(?string $languageId = null) => Loc::getMessage(
			'BIC_SELFHOST_LICENSE_RESTORED_NOTIFY_TITLE',
			language: $languageId,
		);
		$messageCallback = static fn(?string $languageId = null) => Loc::getMessage(
			'BIC_SELFHOST_LICENSE_RESTORED_NOTIFY_TEXT',
			['#DATE#' => $formattedDate],
			$languageId,
		);

		Agent::notifyAllAdmins($titleCallback, $messageCallback);
	}

	private function isWarningShown(): bool
	{
		return Option::get('biconnector', LicenseOption::EXPIRY_WARNING_SHOWN, 'N') === 'Y';
	}

	private function markWarningShown(): void
	{
		Option::set('biconnector', LicenseOption::EXPIRY_WARNING_SHOWN, 'Y');
	}

	private function isEarlyWarningShown(): bool
	{
		return Option::get('biconnector', LicenseOption::EARLY_WARNING_SHOWN, 'N') === 'Y';
	}

	private function markEarlyWarningShown(): void
	{
		if ($this->isEarlyWarningShown())
		{
			return;
		}

		Option::set('biconnector', LicenseOption::EARLY_WARNING_SHOWN, 'Y');
	}

	private function isWorkStopped(): bool
	{
		return Option::get('biconnector', LicenseOption::WORK_STOPPED, 'N') === 'Y';
	}

	/**
	 * The pass repeats itself every day while the term stays over, so the record is written once: every write of an
	 * option drops the cached block of the options of the module.
	 */
	private function markWorkStopped(): void
	{
		if ($this->isWorkStopped())
		{
			return;
		}

		Option::set('biconnector', LicenseOption::WORK_STOPPED, 'Y');
	}

	private function forgetStoppedWork(): void
	{
		Option::set('biconnector', LicenseOption::WORK_STOPPED, 'N');
	}
}
