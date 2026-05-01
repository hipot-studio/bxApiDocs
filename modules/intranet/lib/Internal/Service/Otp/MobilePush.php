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
		$this->otpSettings->setMandatoryUsing(true);

		if ($this->getPromoteMode() === PromoteMode::High)
		{
			$highPromoteService = new HighPromotePushOtp($this);
			$this->otpSettings->setSkipMandatoryDays($highPromoteService->getSkipDaysMandatory());
			$this->setMandatory($highPromoteService->getSkipDaysMandatory());
			$highPromoteService->startStepper();
		}
		else
		{
			$days = $this->otpSettings->getSkipMandatoryDays();
			$this->setMandatory($days);
		}
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
		return [
			['daysFrom' => 0, 'daysTo' => null, 'showEveryDays' => 1],
		];
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
				return $rule['showEveryDays'] ?? 1;
			}
		}

		return 1;
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
