<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\UserOtp;
use Bitrix\Intranet\Entity\UserOtpStatusInfo;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Enum\Otp\UserOtpStatus;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Integration\Security\OtpUserProvider;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\Type\DateTime;
use Bitrix\Security\Mfa\OtpType;

class UserOtpStatusService
{
	private OtpSettings $otpSettings;
	private OtpUserProvider $otpUserProvider;
	private UserRepository $userRepository;
	private bool $isMandatory;
	private bool $isMandatoryPush;
	private PromoteMode $promoteMode;

	public function __construct(
		?OtpSettings $otpSettings = null,
		?MobilePush $mobilePush = null,
		?OtpUserProvider $otpUserProvider = null,
		?UserRepository $userRepository = null,
	)
	{
		$this->otpSettings = $otpSettings ?? new OtpSettings();
		$this->otpUserProvider = $otpUserProvider ?? new OtpUserProvider();
		$this->userRepository = $userRepository ?? new UserRepository();

		$this->isMandatory = $this->otpSettings->isMandatoryUsing();
		$this->isMandatoryPush = $this->isMandatory && $this->otpSettings->isDefaultTypePush();
		$this->promoteMode = ($mobilePush ?? MobilePush::createByDefault())->getPromoteMode();
	}

	public function getStatus(UserOtp $otpInfo, bool $isActiveUser = true, bool $isMandatoryForUser = false): UserOtpStatus
	{
		if (!$this->otpSettings->isAvailable())
		{
			return UserOtpStatus::Disabled;
		}

		if (!$isActiveUser)
		{
			return $otpInfo->isActive ? UserOtpStatus::Enabled : UserOtpStatus::Disabled;
		}

		$isPushType = $otpInfo->isInitialized
			&& $otpInfo->type === OtpType::Push;

		if ($otpInfo->isActive)
		{
			if ($isPushType)
			{
				return UserOtpStatus::Enabled;
			}

			if ($isMandatoryForUser && $this->isMandatoryPush)
			{
				return UserOtpStatus::UpdateRequired;
			}

			if ($this->promoteMode->isGreaterOrEqual(PromoteMode::Medium))
			{
				return UserOtpStatus::UpdateRecommended;
			}

			return UserOtpStatus::Enabled;
		}

		if ($isMandatoryForUser)
		{
			if ($otpInfo->isMandatorySkipped && $otpInfo->dateDeactivate === null)
			{
				return UserOtpStatus::Disabled;
			}

			return UserOtpStatus::EnableRequired;
		}

		return UserOtpStatus::Disabled;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, UserOtpStatusInfo>
	 */
	public function getStatusesByUserIds(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$otpByUser = $this->otpUserProvider->getOtpDataByUserIds($userIds);

		$mandatoryUserIds = $this->getMandatoryUserIdsAmong($userIds);

		$activeUserIds = array_flip($this->userRepository->getActiveConfirmedUserIds($userIds));

		$globalGraceDate = $this->getGlobalGraceDate();

		$result = [];
		foreach ($userIds as $userId)
		{
			$otpInfo = $otpByUser[$userId] ?? new UserOtp(
				userId: $userId,
				isActive: false,
				isInitialized: false,
			);

			$isActive = isset($activeUserIds[$userId]);
			$isMandatory = isset($mandatoryUserIds[$userId]);

			$status = $this->getStatus($otpInfo, $isActive, $isMandatory);
			$graceDate = $this->resolveEffectiveGraceDate($status, $globalGraceDate, $otpInfo->dateDeactivate);

			$result[$userId] = new UserOtpStatusInfo($status, $graceDate);
		}

		return $result;
	}

	private function getGlobalGraceDate(): ?DateTime
	{
		$mobilePush = MobilePush::createByDefault();
		$ts = $mobilePush->getGracePeriod();

		return $ts > 0 ? DateTime::createFromTimestamp($ts) : null;
	}

	private function resolveEffectiveGraceDate(
		UserOtpStatus $status,
		?DateTime $globalGraceDate,
		?DateTime $personalGraceDate,
	): ?DateTime
	{
		if (!in_array($status, [UserOtpStatus::UpdateRequired, UserOtpStatus::EnableRequired], true))
		{
			return null;
		}

		if ($status === UserOtpStatus::EnableRequired && $personalGraceDate === null)
		{
			return null;
		}

		if ($globalGraceDate !== null && $personalGraceDate !== null)
		{
			return $globalGraceDate->getTimestamp() < $personalGraceDate->getTimestamp()
				? $globalGraceDate
				: $personalGraceDate;
		}

		return $globalGraceDate ?? $personalGraceDate;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, true> userId => true for users in mandatory groups
	 */
	private function getMandatoryUserIdsAmong(array $userIds): array
	{
		if (!$this->isMandatory || empty($userIds))
		{
			return [];
		}

		return $this->otpUserProvider->getMandatoryUserIdsAmong($userIds);
	}

	/**
	 * @return UserOtpStatus[]
	 */
	public function getAvailableStatuses(): array
	{
		$statuses = [UserOtpStatus::Enabled];

		if ($this->isMandatoryPush)
		{
			$statuses[] = UserOtpStatus::UpdateRequired;
		}

		if ($this->promoteMode->isGreaterOrEqual(PromoteMode::Medium))
		{
			$statuses[] = UserOtpStatus::UpdateRecommended;
		}

		if ($this->isMandatory)
		{
			$statuses[] = UserOtpStatus::EnableRequired;
		}

		$statuses[] = UserOtpStatus::Disabled;

		return $statuses;
	}

	public function countUsersNeedingAttention(): int
	{
		$mandatoryUserIds = $this->getAllMandatoryUserIds();

		if (empty($mandatoryUserIds))
		{
			return 0;
		}

		if ($this->isMandatoryPush)
		{
			$properOtpUserIds = $this->otpUserProvider->getActivePushOtpUserIds();
		}
		else
		{
			$pushIds = $this->otpUserProvider->getActivePushOtpUserIds();
			$nonPushIds = $this->otpUserProvider->getActiveNonPushOtpUserIds();
			$properOtpUserIds = array_merge($pushIds, $nonPushIds);
		}

		$needAttention = array_diff($mandatoryUserIds, $properOtpUserIds);

		if (empty($needAttention))
		{
			return 0;
		}

		return count($this->userRepository->getActiveConfirmedUserIds($needAttention));
	}

	private function getAllMandatoryUserIds(): array
	{
		if (!$this->isMandatory)
		{
			return [];
		}

		return $this->otpUserProvider->getAllMandatoryUserIds();
	}
}
