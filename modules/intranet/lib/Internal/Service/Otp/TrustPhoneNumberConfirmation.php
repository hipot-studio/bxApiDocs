<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\Date;

class TrustPhoneNumberConfirmation
{
	private const TRUST_PHONE_NUMBER_CONFIRMATION_PERIOD = 7;
	private int $userId;

	public function __construct(private readonly PersonalMobilePush $personalMobilePush, ?int $userId = null)
	{
		$this->userId = $userId ?? (int)CurrentUser::get()->getId();
	}

	public function shouldShowConfirmation(): bool
	{
		if (!$this->personalMobilePush->isPhoneConfirmationRequired())
		{
			return false;
		}

		$now = new Date();
		$lastStartDate = $this->personalMobilePush->getPhoneConfirmationRequiredStartDate();

		if ($lastStartDate?->getDiff($now)->days < 1)
		{
			return false;
		}

		$lastConfirmed = $this->getLastTrustPhoneNumberConfirmationDate();
		if ($lastConfirmed === null)
		{
			return true;
		}

		$now = new Date();
		$diff = $now > $lastConfirmed ? $now->getDiff($lastConfirmed)->days : 0;

		return $diff > self::TRUST_PHONE_NUMBER_CONFIRMATION_PERIOD;
	}

	public function resetLastTrustPhoneNumberConfirmationDate(): void
	{
		\CUserOptions::DeleteOption('intranet', 'otp_phone_number_last_confirmation_date', false, $this->userId);
	}

	private function getLastTrustPhoneNumberConfirmationDate(): ?Date
	{
		$lastConfirmationDate = \CUserOptions::GetOption('intranet', 'otp_phone_number_last_confirmation_date', null, $this->userId);

		if (!empty($lastConfirmationDate))
		{
			try
			{
				return new Date($lastConfirmationDate, 'd.m.Y');
			}
			catch (ObjectException)
			{
				return null;
			}
		}

		return null;
	}
}
