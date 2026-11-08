<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Bitrix24;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Web\Json;
use Bitrix\Security\Mfa\OtpType;

class MobilePush
{
	private const SHOW_ONCE = PHP_INT_MAX - 1;
	private const DO_NOT_SHOW = PHP_INT_MAX;

	private bool $isAvailable;
	private ?array $legacyOtpAllowedUserIds = null;
	private ?array $legacyOtpAllowedUserIdsWithIntegrators = null;
	private ?array $integratorUserIds = null;

	/**
	 * @throws LoaderException
	 */
	public function __construct(
		private readonly OtpSettings $otpSettings,
	) {
		$this->isAvailable = Loader::includeModule('security');
	}

	public static function createByDefault(): self
	{
		return new self(new OtpSettings());
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	public function setByDefault(): void
	{
		if (!$this->isAvailable)
		{
			return;
		}

		$this->otpSettings->setDefaultType(OtpType::Push);
	}

	public function isDefault(): bool
	{
		if (!$this->isAvailable)
		{
			return false;
		}

		return $this->otpSettings->getDefaultType() === OtpType::Push;
	}

	public function isLegacyOtpAllowed(): bool
	{
		return Option::get('intranet', 'legacy_otp_allowed', 'N') === 'Y';
	}

	public function isLegacyOtpAllowedByUserId(int $userId): bool
	{
		if (
			Loader::includeModule('bitrix24')
			&& \CBitrix24::isIntegrator($userId)
		)
		{
			return true;
		}

		return $this->isLegacyOtpAllowed() && in_array($userId, $this->getStoredLegacyOtpAllowedUserIds(), true);
	}

	public function getLegacyOtpAllowedUserIds(): array
	{
		if ($this->legacyOtpAllowedUserIdsWithIntegrators !== null)
		{
			return $this->legacyOtpAllowedUserIdsWithIntegrators;
		}

		$userIds = $this->isLegacyOtpAllowed() ? $this->getStoredLegacyOtpAllowedUserIds() : [];
		$integratorUserIds = $this->getIntegratorUserIds();

		if (!empty($integratorUserIds))
		{
			$userIds = array_values(array_unique([...$userIds, ...$integratorUserIds]));
		}

		$this->legacyOtpAllowedUserIdsWithIntegrators = $userIds;

		return $this->legacyOtpAllowedUserIdsWithIntegrators;
	}

	private function getStoredLegacyOtpAllowedUserIds(): array
	{
		if ($this->legacyOtpAllowedUserIds !== null)
		{
			return $this->legacyOtpAllowedUserIds;
		}

		try
		{
			$json = Option::get('intranet', 'legacy_otp_allowed_users', '[]');
			$userIds = Json::decode($json);
		}
		catch (\Exception)
		{
			$userIds = [];
		}

		if (!is_array($userIds))
		{
			$userIds = [];
		}

		$this->legacyOtpAllowedUserIds = array_map(static fn($id) => (int)$id, $userIds);

		return $this->legacyOtpAllowedUserIds;
	}

	private function getIntegratorUserIds(): array
	{
		if ($this->integratorUserIds !== null)
		{
			return $this->integratorUserIds;
		}

		if (!Loader::includeModule('bitrix24'))
		{
			$this->integratorUserIds = [];

			return $this->integratorUserIds;
		}

		$this->integratorUserIds = array_map(
			static fn($userId) => (int)$userId,
			Bitrix24\Integrator::getIntegratorsId(),
		);

		return $this->integratorUserIds;
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	public function addLegacyOtpAllowedUserId(int $userId): void
	{
		$userIds = $this->getStoredLegacyOtpAllowedUserIds();

		if (!in_array($userId, $userIds, true))
		{
			$userIds[] = $userId;
			Option::set('intranet', 'legacy_otp_allowed_users', Json::encode($userIds));
			$this->legacyOtpAllowedUserIds = $userIds;
			$this->legacyOtpAllowedUserIdsWithIntegrators = null;
		}
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	public function removeLegacyOtpAllowedUserId(int $userId): void
	{
		$userIds = $this->getStoredLegacyOtpAllowedUserIds();
		$filtered = array_values(array_filter($userIds, static fn(int $id) => $id !== $userId));

		if (count($filtered) !== count($userIds))
		{
			Option::set('intranet', 'legacy_otp_allowed_users', Json::encode($filtered));
			$this->legacyOtpAllowedUserIds = $filtered;
			$this->legacyOtpAllowedUserIdsWithIntegrators = null;
		}
	}

	public function gracePeriodEnabled(): bool
	{
		return $this->getGracePeriod() > 0;
	}

	public function getGracePeriod(): int
	{
		return (int)Option::get('intranet', 'push_otp_mandatory_ts', 0);
	}

	public function getGracePeriodStart(): Date
	{
		return Date::createFromTimestamp((int)Option::get('intranet', 'push_otp_mandatory_start', 0));
	}

	public function hasGracePeriod(): bool
	{
		$gracePeriodTs = $this->getGracePeriod();

		return $this->gracePeriodEnabled() && $gracePeriodTs > time();
	}

	public function setMandatory(int $days): void
	{
		Option::set('intranet', 'push_otp_mandatory_start', time());
		Option::set('intranet', 'push_otp_mandatory_ts', time() + $days * 86400);
	}

	public function unsetMandatory(): void
	{
		Option::delete('intranet', ['name' => 'push_otp_mandatory_start']);
		Option::delete('intranet', ['name' => 'push_otp_mandatory_ts']);
	}

	public function getPromoteMode(): PromoteMode
	{
		$defaultPromoteMode = ModuleManager::isModuleInstalled('bitrix24') ? PromoteMode::Medium : PromoteMode::Personal;

		return PromoteMode::tryFrom(Option::get('intranet', 'security_mode', $defaultPromoteMode->value)) ?? PromoteMode::Disable;
	}

	public function isAnnouncesGracePeriodDate(): bool
	{
		return $this->getPromoteMode() === PromoteMode::High;
	}

	public function isGracePeriodEnded(): bool
	{
		$gracePeriodTs = $this->getGracePeriod();

		return $this->gracePeriodEnabled() && $gracePeriodTs <= time();
	}

	public function canUsePersonalModeByUserId(int $userId): bool
	{
		return true;
	}

	public function makeMandatory(): void
	{
		if (!$this->isAvailable)
		{
			return;
		}

		$this->otpSettings->setDefaultType(OtpType::Push);

		$highPromoteService = $this->getPromoteMode() === PromoteMode::High
			? new HighPromotePushOtp($this)
			: null;

		if ((new MandatoryOtpPolicy())->isTransitionPeriodOwnedByPortal())
		{
			$days = (new MandatoryPeriodProvider($this->otpSettings))->getCurrentPeriod();
		}
		else
		{
			$days = ($highPromoteService ?? new HighPromotePushOtp($this))->getSkipDaysMandatory();
			$this->otpSettings->setSkipMandatoryDays($days);
		}

		$this->setMandatory($days);
		$this->otpSettings->setMandatoryUsing(true);

		$highPromoteService?->startStepper($days);
	}

	public function getGracePeriodSchedule(): array
	{
		$scheduleJson = Option::get('intranet', 'push_otp_grace_period_schedule', '');

		if (!empty($scheduleJson))
		{
			try
			{
				$schedule = Json::decode($scheduleJson);
				if (is_array($schedule) && $this->validateSchedule($schedule))
				{
					return $schedule;
				}
			}
			catch (\Bitrix\Main\ArgumentException $e)
			{
				return $this->getDefaultGracePeriodSchedule();
			}
		}

		return $this->getDefaultGracePeriodSchedule();
	}

	public function setGracePeriodSchedule(array $schedule): bool
	{
		if (!$this->validateSchedule($schedule))
		{
			return false;
		}

		try
		{
			Option::set('intranet', 'push_otp_grace_period_schedule', Json::encode($schedule));
		}
		catch (\Bitrix\Main\ArgumentException $e)
		{
			return false;
		}

		return true;
	}

	public function resetGracePeriodSchedule(): void
	{
		Option::delete('intranet', ['name' => 'push_otp_grace_period_schedule']);
	}

	public function getDefaultGracePeriodSchedule(): array
	{
		$gracePeriodEnd = Date::createFromTimestamp($this->getGracePeriod());
		$gracePeriodDays = $this->getGracePeriodStart()->getDiff($gracePeriodEnd)->days;
		$firstShowDay = max(0, $gracePeriodDays - 30);
		$secondShowDay = max(0, $gracePeriodDays - 7);
		$dailyShowDay = max(0, $gracePeriodDays - 3);
		return array_values(array_filter([
			['daysFrom' => 0, 'daysTo' => $firstShowDay, 'showEveryDays' => self::DO_NOT_SHOW],
			['daysFrom' => $firstShowDay, 'daysTo' => $secondShowDay, 'showEveryDays' => self::SHOW_ONCE],
			['daysFrom' => $secondShowDay, 'daysTo' => $dailyShowDay, 'showEveryDays' => self::SHOW_ONCE],
			['daysFrom' => $dailyShowDay, 'daysTo' => null, 'showEveryDays' => 1],
		], static fn(array $rule): bool => $rule['daysTo'] === null || $rule['daysTo'] > $rule['daysFrom']));
	}

	public function getShowFrequencyForDay(int $daysSinceGraceStart): int
	{
		$schedule = $this->getGracePeriodSchedule();

		foreach ($schedule as $rule)
		{
			$from = $rule['daysFrom'] ?? 0;
			$to = $rule['daysTo'] ?? PHP_INT_MAX;

			if ($daysSinceGraceStart >= $from && $daysSinceGraceStart < $to)
			{
				$frequency = $rule['showEveryDays'] ?? 1;
				return $frequency === self::SHOW_ONCE ? $daysSinceGraceStart - $from + 1 : $frequency;
			}
		}

		return 1;
	}

	public function canShowByGracePeriodSchedule(int $daysSinceGraceStart, ?int $daysSinceLastShow): bool
	{
		if ($daysSinceLastShow === null)
		{
			try
			{
				$customSchedule = Json::decode(Option::get('intranet', 'push_otp_grace_period_schedule', ''));
			}
			catch (\Bitrix\Main\ArgumentException)
			{
				$customSchedule = null;
			}

			if (is_array($customSchedule) && $this->validateSchedule($customSchedule))
			{
				return true;
			}

			$daysSinceLastShow = $daysSinceGraceStart + 1;
		}

		return $daysSinceLastShow >= $this->getShowFrequencyForDay($daysSinceGraceStart);
	}

	private function validateSchedule(array $schedule): bool
	{
		if (empty($schedule))
		{
			return false;
		}

		foreach ($schedule as $rule)
		{
			if (!is_array($rule))
			{
				return false;
			}

			if (!isset($rule['daysFrom'], $rule['showEveryDays']))
			{
				return false;
			}

			if (!is_numeric($rule['daysFrom']) || !is_numeric($rule['showEveryDays']))
			{
				return false;
			}

			if ($rule['daysFrom'] < 0 || $rule['showEveryDays'] < 1)
			{
				return false;
			}

			if (isset($rule['daysTo']))
			{
				if (!is_numeric($rule['daysTo']) || $rule['daysTo'] <= $rule['daysFrom'])
				{
					return false;
				}
			}
		}

		return true;
	}
}
