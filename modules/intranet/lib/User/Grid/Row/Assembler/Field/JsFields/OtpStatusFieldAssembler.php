<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Grid\Row\Assembler\Field\JsFields;

use Bitrix\Intranet\Entity\UserOtpStatusInfo;
use Bitrix\Intranet\Internal\Enum\Otp\UserOtpStatus;
use Bitrix\Intranet\Internal\Service\Otp\UserOtpStatusService;
use Bitrix\Main\Context;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;

class OtpStatusFieldAssembler extends JsExtensionFieldAssembler
{
	private ?array $statusCache = null;
	private ?UserOtpStatusService $service = null;

	public function __construct(array $columnIds, ?Settings $settings = null)
	{
		parent::__construct($columnIds, $settings);
	}

	protected function getExtensionClassName(): string
	{
		return 'OtpStatusField';
	}

	protected function getRenderParams($rawValue): array
	{
		$userId = (int)($rawValue['ID'] ?? 0);
		$statusInfo = $this->getStatusInfo($userId);

		return [
			'status' => $statusInfo->status->value,
			'label' => $this->getLabelByStatus($statusInfo->status),
			'hint' => $this->getHintByStatusInfo($statusInfo),
		];
	}

	protected function prepareColumnForExport($data): string
	{
		$userId = (int)($data['ID'] ?? 0);
		$statusInfo = $this->getStatusInfo($userId);

		return $this->getLabelByStatus($statusInfo->status);
	}

	private function getStatusInfo(int $userId): UserOtpStatusInfo
	{
		$cache = $this->getStatusCache();

		return $cache[$userId] ?? new UserOtpStatusInfo(UserOtpStatus::Disabled);
	}

	private function getStatusCache(): array
	{
		if ($this->statusCache !== null)
		{
			return $this->statusCache;
		}

		$this->statusCache = [];

		$service = $this->getService();
		$userCollection = $this->getSettings()->getUserCollection();
		if ($userCollection === null)
		{
			return $this->statusCache;
		}

		$userIds = [];
		foreach ($userCollection as $user)
		{
			$userIds[] = $user->getId();
		}

		if (!empty($userIds))
		{
			$this->statusCache = $service->getStatusesByUserIds($userIds);
		}

		return $this->statusCache;
	}

	private function getService(): UserOtpStatusService
	{
		if ($this->service === null)
		{
			$this->service = new UserOtpStatusService();
		}

		return $this->service;
	}

	private function getLabelByStatus(UserOtpStatus $status): string
	{
		return match ($status) {
			UserOtpStatus::Enabled => Loc::getMessage('INTRANET_USER_GRID_OTP_STATUS_ENABLED') ?? '',
			UserOtpStatus::UpdateRequired => Loc::getMessage('INTRANET_USER_GRID_OTP_STATUS_UPDATE_REQUIRED') ?? '',
			UserOtpStatus::UpdateRecommended => Loc::getMessage('INTRANET_USER_GRID_OTP_STATUS_UPDATE_RECOMMENDED') ?? '',
			UserOtpStatus::EnableRequired => Loc::getMessage('INTRANET_USER_GRID_OTP_STATUS_ENABLE_REQUIRED') ?? '',
			UserOtpStatus::Disabled => Loc::getMessage('INTRANET_USER_GRID_OTP_STATUS_DISABLED') ?? '',
		};
	}

	private function getHintByStatusInfo(UserOtpStatusInfo $info): string
	{
		$status = $info->status;

		if (
			$info->graceDate !== null
			&& in_array($status, [UserOtpStatus::UpdateRequired, UserOtpStatus::EnableRequired], true)
		)
		{
			$formattedDate = FormatDate(
				Context::getCurrent()->getCulture()->getDayMonthFormat(),
				$info->graceDate->getTimestamp(),
			);

			$messageKey = match ($status) {
				UserOtpStatus::UpdateRequired => 'INTRANET_USER_GRID_OTP_STATUS_HINT_UPDATE_REQUIRED',
				UserOtpStatus::EnableRequired => 'INTRANET_USER_GRID_OTP_STATUS_HINT_ENABLE_REQUIRED',
			};

			return str_replace('#DATE#', $formattedDate, Loc::getMessage($messageKey) ?? '');
		}

		$messageKey = match ($status) {
			UserOtpStatus::Enabled => 'INTRANET_USER_GRID_OTP_STATUS_HINT_ENABLED',
			UserOtpStatus::UpdateRequired => 'INTRANET_USER_GRID_OTP_STATUS_HINT_UPDATE_REQUIRED_NO_DATE',
			UserOtpStatus::UpdateRecommended => 'INTRANET_USER_GRID_OTP_STATUS_HINT_UPDATE_RECOMMENDED',
			UserOtpStatus::EnableRequired => 'INTRANET_USER_GRID_OTP_STATUS_HINT_ENABLE_REQUIRED_NO_DATE',
			UserOtpStatus::Disabled => 'INTRANET_USER_GRID_OTP_STATUS_HINT_DISABLED',
		};

		return Loc::getMessage($messageKey) ?? '';
	}
}
