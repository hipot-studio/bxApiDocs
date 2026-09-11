<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Type\DateTime;

/**
 * The only reading point of the license extension state.
 *
 * The expiry date is remembered for the request, the verdict is not: it is compared with the current moment
 * on every read, so expiration happens at its own moment instead of depending on a cache lifetime.
 *
 * Work stops not on the expiry date but after the grace that follows it, the same way the license of the box
 * itself works.
 */
final class SelfHostedLicense
{
	/**
	 * Start of the year the capability was released (2026-01-01 UTC). A smaller value cannot be a term of this
	 * extension, and it catches garbage: a date string like 2027-01-31 casts to the year number, that is to 1970.
	 *
	 * The bound is the year and not the day of the release on purpose: the work stops the grace after the term, so
	 * a bound at the release day would leave the blocked state unreachable for weeks after it.
	 */
	private const RELEASE_FLOOR_TS = 1767225600;
	private const MAX_FUTURE_INTERVAL = 10 * 365 * 86400;

	/**
	 * Codes of the editions the extension is sold to. Two of them, because the purchase page sells two: Enterprise
	 * and the pricier Enterprise Holding. The registry of a box lists its codes cumulatively - a stand with the
	 * top edition carries `Portal,Communications,Enterprise,Holding` - so `Enterprise` alone would already cover
	 * both, and the second code is kept for an edition that turns out to carry only it.
	 */
	private const EXTENDED_EDITION_CODES = ['Enterprise', 'Holding'];

	/**
	 * Days between the end of the term and the stop of the work. The same length the license of the box is given,
	 * so a client renewing both at once meets one rule instead of two.
	 *
	 * The instance of the analytics server holds a grace of its own: it is given the term as it is, and closes
	 * itself by the same rule (`SelfHostedLicenseService.GRACE_SECONDS` on its side). Two readings of one value,
	 * so both sides must be changed together.
	 */
	private const GRACE_DAYS = 15;

	private const REJECT_REASON_NOT_NUMERIC = 'notNumeric';
	private const REJECT_REASON_OUT_OF_RANGE = 'outOfRange';

	private static ?self $instance = null;

	private bool $isExpiryDateResolved = false;
	private ?DateTime $expiryDate = null;
	private bool $isCheckDisabledLogged = false;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public static function reset(): void
	{
		self::$instance = null;
	}

	public function getState(): SelfHostedLicenseState
	{
		if ($this->isCheckDisabled())
		{
			return SelfHostedLicenseState::Active;
		}

		$expiryDate = $this->getExpiryDate();
		if ($expiryDate === null)
		{
			return SelfHostedLicenseState::None;
		}

		$now = new DateTime();
		if ($expiryDate >= $now)
		{
			return SelfHostedLicenseState::Active;
		}

		return $this->getBlockDate() >= $now ? SelfHostedLicenseState::Grace : SelfHostedLicenseState::Expired;
	}

	/**
	 * The day the work stops: the end of the term plus the grace. Null when there is no term, and then there is
	 * nothing to stop either.
	 */
	public function getBlockDate(): ?DateTime
	{
		$expiryDate = $this->getExpiryDate();

		return $expiryDate === null
			? null
			: DateTime::createFromTimestamp($expiryDate->getTimestamp() + $this->getGraceDays() * 86400)
		;
	}

	/**
	 * The length of the grace is a rule of the product, so the constant is the answer; the option is for a stand
	 * and for tests, where the blocked state has to be reached without waiting out the grace.
	 *
	 * The instance is not told about the option: it counts its own fifteen days from the same term, so a shortened
	 * grace stops the portal earlier than the instance. That is the point of the lever - checking one side at a
	 * time - and not a state a client can end up in.
	 */
	private function getGraceDays(): int
	{
		$configured = (int)Option::get('biconnector', LicenseOption::GRACE_DAYS, (string)self::GRACE_DAYS);

		return max(0, $configured);
	}

	public function getExpiryDate(): ?DateTime
	{
		if (!$this->isExpiryDateResolved)
		{
			$this->expiryDate = $this->readExpiryDate();
			$this->isExpiryDateResolved = true;
		}

		return $this->expiryDate;
	}

	/**
	 * An empty code registry means there is no reliable information about the edition, not a wrong edition,
	 * so the extension stays allowed until the registry is filled.
	 */
	public function isAllowedByEdition(): bool
	{
		$codes = Application::getInstance()->getLicense()->getCodes();

		return $codes === [] || array_intersect(self::EXTENDED_EDITION_CODES, $codes) !== [];
	}

	private function readExpiryDate(): ?DateTime
	{
		$raw = (string)Option::get(LicenseOption::EXPIRY_DATE_MODULE, LicenseOption::EXPIRY_DATE, '');
		if ($raw === '')
		{
			return null;
		}

		if (preg_match('/^\d+$/', $raw) !== 1)
		{
			$this->logRejectedValue($raw, self::REJECT_REASON_NOT_NUMERIC);

			return null;
		}

		$timestamp = (int)$raw;
		if ($timestamp < self::RELEASE_FLOOR_TS || $timestamp > time() + self::MAX_FUTURE_INTERVAL)
		{
			$this->logRejectedValue($raw, self::REJECT_REASON_OUT_OF_RANGE);

			return null;
		}

		return DateTime::createFromTimestamp($timestamp);
	}

	private function isCheckDisabled(): bool
	{
		if (Option::get('biconnector', LicenseOption::CHECK_DISABLED, 'N') !== 'Y')
		{
			return false;
		}

		if (!$this->isCheckDisabledLogged)
		{
			$this->isCheckDisabledLogged = true;
			Logger::logInfo('Self-hosted license check is disabled by option', [
				'option' => LicenseOption::CHECK_DISABLED,
			]);
		}

		return true;
	}

	private function logRejectedValue(string $raw, string $reason): void
	{
		Logger::logWarning(
			[new Error('Self-hosted license expiry date value rejected')],
			[
				'reason' => $reason,
				'value' => $raw,
			],
		);
	}
}
