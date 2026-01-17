<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Web\Json;
use Bitrix\Security\Mfa\OtpType;

class MobilePush
{
	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function __construct(
		private readonly OtpSettings $otpSettings,
	) {
		if (!Loader::includeModule('security'))
		{
			throw new SystemException('Module security is not installed');
		}
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
		$this->otpSettings->setDefaultType(OtpType::Push);
	}

	public function isDefault(): bool
	{
		return $this->otpSettings->getDefaultType() === OtpType::Push;
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
		return PromoteMode::tryFrom(Option::get('intranet', 'security_mode', 'disable')) ?? PromoteMode::Disable;
	}

	public function isGracePeriodEnded(): bool
	{
		$gracePeriodTs = $this->getGracePeriod();

		return $this->gracePeriodEnabled() && $gracePeriodTs <= time();
	}

	public function canUsePersonalModeByUserId(int $userId): bool
	{
		return $this->getPromoteMode() === PromoteMode::Personal
			&& \CUserOptions::GetOption('intranet', 'personal_security_mode', 'N', $userId) === 'Y';
	}

	public function makeMandatory(): void
	{
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
			['daysFrom' => 0, 'daysTo' => 3, 'showEveryDays' => 1],
			['daysFrom' => 3, 'daysTo' => 23, 'showEveryDays' => 5],
			['daysFrom' => 23, 'daysTo' => null, 'showEveryDays' => 1],
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
