<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Infrastructure\Update\Otp\HighPromotePushOtpSwitcher;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Enum\StepperStatus;
use Bitrix\Main\Config\Option;

class HighPromotePushOtp
{
	public function __construct(
		private readonly MobilePush $mobilePush,
	) {
	}

	public function startStepper(): bool
	{
		if ($this->canStartStepper())
		{
			HighPromotePushOtpSwitcher::bind($this->getSkipDaysMandatory() * 86400);
			$this->setStepperStatus(StepperStatus::Scheduled);

			return true;
		}

		return false;
	}

	public function setStepperStatus(StepperStatus $statusStepper): void
	{
		Option::set('intranet', 'high_promote_push_otp_status_stepper', $statusStepper->value);
	}

	public function getStepperStatus(): StepperStatus
	{
		return StepperStatus::tryFrom(
			Option::get('intranet', 'high_promote_push_otp_status_stepper', StepperStatus::Idle->value)
		) ?? StepperStatus::Idle;
	}

	public function getSkipDaysMandatory(): int
	{
		return (int)Option::get('intranet', 'high_promote_push_otp_mandatory_days', 30);
	}

	private function canStartStepper(): bool
	{
		return $this->mobilePush->isDefault()
			&& $this->mobilePush->getPromoteMode() === PromoteMode::High
			&& $this->getStepperStatus()->canStart()
		;
	}
}
