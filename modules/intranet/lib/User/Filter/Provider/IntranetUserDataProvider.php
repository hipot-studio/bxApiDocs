<?php

namespace Bitrix\Intranet\User\Filter\Provider;

use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Enum\Otp\UserOtpStatus;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Integration\Security\OtpUserProvider;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Intranet\Internal\Service\Otp\UserOtpStatusService;
use Bitrix\Intranet\User\Filter\IntranetUserSettings;
use Bitrix\Intranet\Util;
use Bitrix\Main\Filter\EntityDataProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class IntranetUserDataProvider extends EntityDataProvider
{
	public const PHONE_APPS_FIELD = 'PHONE_APPS';
	public const DESKTOP_APPS_FIELD = 'DESKTOP_APPS';

	private const ANDROID_APP = 'android';
	private const IOS_APP = 'ios';
	private const LINUX_APP = 'linux';
	private const MAC_APP = 'mac';
	private const WINDOWS_APP = 'windows';
	private const NOT_INSTALLED_APP = 'notInstalled';
	private IntranetUserSettings $settings;
	private ?UserOtpStatusService $otpStatusService = null;

	public function __construct(IntranetUserSettings $settings)
	{
		$this->settings = $settings;
	}

	public function getSettings(): IntranetUserSettings
	{
		return $this->settings;
	}

	public function prepareFields(): array
	{
		$fieldList['PHONE_APPS'] = $this->createField(
			'PHONE_APPS',
			[
				'name' => Loc::getMessage('INTRANET_USER_FILTER_MOBILE_APP') ?? '',
				'type' => 'list',
				'partial' => true,
			]
		);

		$fieldList['DESKTOP_APPS'] = $this->createField(
			'DESKTOP_APPS',
			[
				'name' => Loc::getMessage('INTRANET_USER_FILTER_DESKTOP_APP') ?? '',
				'type' => 'list',
				'partial' => true,
			]
		);

		if ($this->getSettings()->isFilterAvailable(IntranetUserSettings::WAIT_CONFIRMATION_FIELD))
		{
			$fieldList[IntranetUserSettings::WAIT_CONFIRMATION_FIELD] = $this->createField(
				IntranetUserSettings::WAIT_CONFIRMATION_FIELD,
				[
					'name' => Loc::getMessage('INTRANET_USER_FILTER_WAIT_CONFIRMATION') ?? '',
					'type' => 'checkbox',
				]
			);
		}

		if ($this->getSettings()->isFilterAvailable(IntranetUserSettings::OTP_STATUS_FIELD))
		{
			$fieldList[IntranetUserSettings::OTP_STATUS_FIELD] = $this->createField(
				IntranetUserSettings::OTP_STATUS_FIELD,
				[
					'name' => Loc::getMessage('INTRANET_USER_FILTER_OTP_STATUS') ?? '',
					'type' => 'list',
					'partial' => true,
				],
			);
		}

		return $fieldList;
	}

	public function prepareFieldData($fieldID): array
	{
		$result = [];

		if ($fieldID === self::PHONE_APPS_FIELD)
		{
			$result = [
				'params' => ['multiple' => 'Y'],
				'items' => [
					self::NOT_INSTALLED_APP => Loc::getMessage('INTRANET_USER_FILTER_APP_NOT_INSTALLED'),
					self::ANDROID_APP => Loc::getMessage('INTRANET_USER_FILTER_MOBILE_APP_ANDROID'),
					self::IOS_APP => Loc::getMessage('INTRANET_USER_FILTER_MOBILE_APP_IOS'),
				],
			];
		}

		if ($fieldID === self::DESKTOP_APPS_FIELD)
		{
			$result = [
				'params' => ['multiple' => 'Y'],
				'items' => [
					self::NOT_INSTALLED_APP => Loc::getMessage('INTRANET_USER_FILTER_APP_NOT_INSTALLED'),
					self::WINDOWS_APP => Loc::getMessage('INTRANET_USER_FILTER_DESKTOP_APP_WINDOWS'),
					self::MAC_APP => Loc::getMessage('INTRANET_USER_FILTER_DESKTOP_APP_MAC'),
					self::LINUX_APP => Loc::getMessage('INTRANET_USER_FILTER_DESKTOP_APP_LINUX'),
				],
			];
		}

		if ($fieldID === IntranetUserSettings::OTP_STATUS_FIELD)
		{
			$items = [];
			$service = $this->getOtpStatusService();

			foreach ($service->getAvailableStatuses() as $status)
			{
				$items[$status->value] = Loc::getMessage('INTRANET_USER_FILTER_OTP_STATUS_' . mb_strtoupper($status->value)) ?? $status->value;
			}

			$result = [
				'params' => ['multiple' => 'Y'],
				'items' => $items,
			];
		}

		return $result;
	}

	public function prepareFilterValue(array $rawFilterValue): array
	{
		$filterValue = parent::prepareFilterValue($rawFilterValue);

		// compatibility with old filters
		$this->checkFiredField($filterValue);
		$this->checkAdminField($filterValue);
		$this->checkIntegratorField($filterValue);
		$this->checkOnlineField($filterValue);
		$this->checkVisitorField($filterValue);
		$this->checkTagsField($filterValue);
		$this->checkInvitedField($filterValue);
		$this->checkWaitConfirmationField($filterValue);
		$this->checkAppField($filterValue);
		$this->checkOtpStatusField($filterValue);

		return $filterValue;
	}

	private function checkFiredField(array &$filterValue): void
	{
		if ($this->getSettings()->isFilterAvailable(IntranetUserSettings::INVITED_FIELD))
		{
			$invitedFilter = [
				'=ACTIVE' => 'Y',
				'!CONFIRM_CODE' => '',
			];

			if (!$this->getSettings()->isCurrentUserAdmin())
			{
				$invitedFilter['INVITATION.ORIGINATOR_ID'] = $this->getSettings()->getCurrentUserId();
			}
		}
		else
		{
			$invitedFilter = [];
		}

		if ($this->getSettings()->isFilterAvailable(IntranetUserSettings::WAIT_CONFIRMATION_FIELD))
		{
			$waitingFilter = [
				'=ACTIVE' => 'N',
				'!CONFIRM_CODE' => '',
			];
		}
		else
		{
			$waitingFilter = [];
		}

		if (
			empty($filterValue[IntranetUserSettings::FIRED_FIELD])
			&& $this->getSettings()->isFilterAvailable(IntranetUserSettings::FIRED_FIELD)
		)
		{
			$filterValue[] = [
				'LOGIC' => 'OR',
				$waitingFilter,
				$invitedFilter,
				[
					'=ACTIVE' => 'Y',
					'CONFIRM_CODE' => '',
				],
				[
					'=ACTIVE' => 'N',
					'CONFIRM_CODE' => '',
				],
			];
		}
		elseif (
			!$this->getSettings()->isFilterAvailable(IntranetUserSettings::FIRED_FIELD)
			|| empty($filterValue[IntranetUserSettings::FIRED_FIELD])
			|| $filterValue[IntranetUserSettings::FIRED_FIELD] === 'N'
		)
		{
			$filterValue[] = [
				'LOGIC' => 'OR',
				$waitingFilter,
				$invitedFilter,
				[
					'=ACTIVE' => 'Y',
					'CONFIRM_CODE' => '',
				]
			];
		}
		elseif (
			$this->getSettings()->isFilterAvailable(IntranetUserSettings::FIRED_FIELD)
			&& isset($filterValue[IntranetUserSettings::FIRED_FIELD])
			&& $filterValue[IntranetUserSettings::FIRED_FIELD] === 'Y'
		)
		{
			$filterValue['=ACTIVE'] = 'N';
			$filterValue['CONFIRM_CODE'] = '';
		}
	}

	private function checkVisitorField(array &$filterValue): void
	{
		if (
			!empty($filterValue[IntranetUserSettings::VISITOR_FIELD])
			&& $filterValue[IntranetUserSettings::VISITOR_FIELD] === 'Y'
			&& $this->getSettings()->isFilterAvailable(IntranetUserSettings::VISITOR_FIELD)
		)
		{
			$filterValue['UF_DEPARTMENT'] = false;

			if (Loader::includeModule('extranet'))
			{
				$filterValue[] = ['=EXTRANET.ID' => null];
			}
		}
		elseif (
			!$this->getSettings()->isFilterAvailable(IntranetUserSettings::VISITOR_FIELD)
			|| (
				!empty($filterValue[IntranetUserSettings::VISITOR_FIELD])
				&& $filterValue[IntranetUserSettings::VISITOR_FIELD] === 'N'
			)
		)
		{
			if (Loader::includeModule('extranet'))
			{
				$filterValue[] = [
					'LOGIC' => 'OR',
					'!UF_DEPARTMENT' => false,
					'!=EXTRANET.ID' => null,
				];
			}
			else
			{
				$filterValue['!UF_DEPARTMENT'] = false;
			}
		}
	}

	private function checkInvitedField(array &$filterValue): void
	{
		if (
			!empty($filterValue[IntranetUserSettings::INVITED_FIELD])
			&& $this->getSettings()->isFilterAvailable(IntranetUserSettings::INVITED_FIELD)
		)
		{
			if ($filterValue[IntranetUserSettings::INVITED_FIELD] === 'Y')
			{
				$filterValue['=ACTIVE'] = 'Y';
				$filterValue['!CONFIRM_CODE'] = '';
			}
			elseif ($filterValue[IntranetUserSettings::INVITED_FIELD] === 'N')
			{
				$filterValue[] = [
					'LOGIC' => 'OR',
					[
						'=ACTIVE' => 'N',
						'!CONFIRM_CODE' => '',
					],
					[
						'=ACTIVE' => 'Y',
						'CONFIRM_CODE' => '',
					]
				];
			}
		}
	}

	private function checkIntegratorField(array &$filterValue): void
	{
		if (
			!empty($filterValue[IntranetUserSettings::INTEGRATOR_FIELD])
			&& $filterValue[IntranetUserSettings::INTEGRATOR_FIELD] === 'Y'
			&& $this->getSettings()->isFilterAvailable(IntranetUserSettings::INTEGRATOR_FIELD)
			&& Loader::includeModule('bitrix24')
		)
		{
			$integratorGroupId = \Bitrix\Bitrix24\Integrator::getIntegratorGroupId();
			if ($integratorGroupId)
			{
				$filterValue['=GROUPS.GROUP_ID'] = $integratorGroupId;
			}
		}
	}

	private function checkAdminField(array &$filterValue): void
	{
		if (
			!empty($filterValue[IntranetUserSettings::ADMIN_FIELD])
			&& $filterValue[IntranetUserSettings::ADMIN_FIELD] === 'Y'
			&& $this->getSettings()->isFilterAvailable(IntranetUserSettings::ADMIN_FIELD)
		)
		{
			$filterValue['=GROUPS.GROUP_ID'] = 1;

			if (
				Loader::includeModule('bitrix24')
				&& $this->getSettings()->isFilterAvailable(IntranetUserSettings::INTEGRATOR_FIELD)
			)
			{
				$integratorGroupId = \Bitrix\Bitrix24\Integrator::getIntegratorGroupId();

				if ($integratorGroupId)
				{
					$filterValue['!=GROUPS.GROUP_ID'] = $integratorGroupId;
				}
			}
		}
	}

	private function checkOnlineField(array &$filterValue): void
	{
		if (
			!empty($filterValue['IS_ONLINE'])
			&& in_array($filterValue['IS_ONLINE'], [ 'Y', 'N' ])
		)
		{
			$filterValue['IS_ONLINE'] = (
				$filterValue['IS_ONLINE'] === 'Y' ? 'Y' : 'N'
			);
		}
	}

	private function checkTagsField(array &$filterValue): void
	{
		if (isset($filterValue['TAGS']))
		{
			$tagsSearchValue = trim($filterValue['TAGS']);
			if ($tagsSearchValue <> '')
			{
				$filterValue['%=TAGS.NAME'] = $tagsSearchValue.'%';
			}
		}
	}

	private function checkWaitConfirmationField(array &$filterValue): void
	{
		if (isset($filterValue[IntranetUserSettings::WAIT_CONFIRMATION_FIELD]))
		{
			if ($filterValue[IntranetUserSettings::WAIT_CONFIRMATION_FIELD] === 'Y')
			{
				$filterValue['=ACTIVE'] = 'N';
				$filterValue['!CONFIRM_CODE'] = '';
			}
			elseif ($filterValue[IntranetUserSettings::WAIT_CONFIRMATION_FIELD] === 'N')
			{
				$filterValue['CONFIRM_CODE'] = '';
			}
		}
	}

	private function checkAppField(array &$filterValue): void
	{
		if (!empty($filterValue[self::PHONE_APPS_FIELD]))
		{
			$filter = [];

			if (in_array(self::NOT_INSTALLED_APP, $filterValue[self::PHONE_APPS_FIELD]))
			{
				$filter[] = ['!@ID' => $this->getUsersPhoneApps()];
			}

			if (in_array(self::ANDROID_APP, $filterValue[self::PHONE_APPS_FIELD]))
			{
				$filter[] = ['@ID' => $this->getUsersPhoneApps(self::ANDROID_APP)];
			}

			if (in_array(self::IOS_APP, $filterValue[self::PHONE_APPS_FIELD]))
			{
				$filter[] = ['@ID' => $this->getUsersPhoneApps(self::IOS_APP)];
			}

			if (!empty($filter))
			{
				$filterValue[] = [
					'LOGIC' => 'OR',
					...$filter
				];
			}
		}

		if (!empty($filterValue[self::DESKTOP_APPS_FIELD]))
		{
			$filter = [];

			if (in_array(self::NOT_INSTALLED_APP, $filterValue[self::DESKTOP_APPS_FIELD]))
			{
				$filter[] = ['!@ID' => $this->getUsersDesktopApps()];
			}

			if (in_array(self::WINDOWS_APP, $filterValue[self::DESKTOP_APPS_FIELD]))
			{
				$filter[] = ['@ID' => $this->getUsersDesktopApps(self::WINDOWS_APP)];
			}

			if (in_array(self::MAC_APP, $filterValue[self::DESKTOP_APPS_FIELD]))
			{
				$filter[] = ['@ID' => $this->getUsersDesktopApps(self::MAC_APP)];
			}

			if (in_array(self::LINUX_APP, $filterValue[self::DESKTOP_APPS_FIELD]))
			{
				$filter[] = ['@ID' => $this->getUsersDesktopApps(self::LINUX_APP)];
			}

			if (!empty($filter))
			{
				$filterValue[] = [
					'LOGIC' => 'OR',
					...$filter
				];
			}
		}
	}

	private function getOsOptionName(?string $osName): ?string
	{
		return match ($osName) {
			self::ANDROID_APP => 'AndroidLastActivityDate',
			self::IOS_APP => 'iOsLastActivityDate',
			self::MAC_APP => 'MacLastActivityDate',
			self::WINDOWS_APP => 'WindowsLastActivityDate',
			self::LINUX_APP => 'LinuxLastActivityDate',
			default => null,
		};
	}

	private function getUsersDesktopApps(string $osName = null): array
	{
		$optionNames = $this->getOsOptionName($osName) ?? [
				$this->getOsOptionName(self::MAC_APP),
				$this->getOsOptionName(self::WINDOWS_APP),
				$this->getOsOptionName(self::LINUX_APP),
			];

		return $this->getUserIdsOptions('im', $optionNames);
	}

	private function getUsersPhoneApps(string $osName = null): array
	{
		$optionNames = $this->getOsOptionName($osName) ?? [
				$this->getOsOptionName(self::ANDROID_APP),
				$this->getOsOptionName(self::IOS_APP),
			];

		return $this->getUserIdsOptions('mobile', $optionNames);
	}

	private function getUserIdsOptions(string $category, array|string $names): array
	{
		$userIds = [];

		$result = \CUserOptions::GetList([],
			[
				'CATEGORY' => $category,
				is_array($names) ? '@NAME' : 'NAME' => $names,
			]
		);
		$appActivityTimeout = Util::getAppsActivityTimeout();

		while ($option = $result->Fetch())
		{
			if ($option['VALUE'])
			{
				$value = unserialize($option['VALUE'], ['allowed_classes' => false]);

				if (is_int($value) && $value > time() - $appActivityTimeout)
				{
					$userIds[] = $option['USER_ID'];
				}
			}
		}

		if (empty($userIds))
		{
			return [0];
		}

		return $userIds;
	}

	private function getOtpStatusService(): UserOtpStatusService
	{
		if ($this->otpStatusService === null)
		{
			$this->otpStatusService = new UserOtpStatusService();
		}

		return $this->otpStatusService;
	}

	private function checkOtpStatusField(array &$filterValue): void
	{
		if (
			empty($filterValue[IntranetUserSettings::OTP_STATUS_FIELD])
			|| !$this->getSettings()->isFilterAvailable(IntranetUserSettings::OTP_STATUS_FIELD)
		)
		{
			return;
		}

		$selectedStatuses = (array)$filterValue[IntranetUserSettings::OTP_STATUS_FIELD];
		unset($filterValue[IntranetUserSettings::OTP_STATUS_FIELD]);

		$otpSettings = new OtpSettings();
		$mobilePush = MobilePush::createByDefault();
		$otpUserProvider = new OtpUserProvider();

		$statusSets = $this->computeOtpStatusIdSets($otpSettings, $mobilePush, $otpUserProvider);

		$selectedEnums = [];
		foreach ($selectedStatuses as $statusValue)
		{
			$status = UserOtpStatus::tryFrom($statusValue);
			if ($status !== null)
			{
				$selectedEnums[] = $status;
			}
		}

		if (empty($selectedEnums))
		{
			return;
		}

		$this->applyOtpStatusFilter($filterValue, $selectedEnums, $statusSets);
	}

	private function computeOtpStatusIdSets(
		OtpSettings $otpSettings,
		MobilePush $mobilePush,
		OtpUserProvider $otpUserProvider,
	): array
	{
		$pushOtp = $otpUserProvider->getActivePushOtpUserIds();
		$nonPushOtp = $otpUserProvider->getActiveNonPushOtpUserIds();
		$allActiveOtp = array_merge($pushOtp, $nonPushOtp);

		$isMandatory = $otpSettings->isMandatoryUsing();
		$isMandatoryPush = $isMandatory && $otpSettings->isDefaultTypePush();
		$promoteMode = $mobilePush->getPromoteMode();

		$mandatory = [];
		if ($isMandatory)
		{
			$mandatory = $otpUserProvider->getAllMandatoryUserIds();
		}

		$needCheck = [];
		if ($isMandatory)
		{
			$needCheck = array_diff($mandatory, $allActiveOtp);
		}
		if ($isMandatoryPush)
		{
			$needCheck = array_unique(array_merge($needCheck, array_intersect($nonPushOtp, $mandatory)));
		}
		if ($promoteMode->isGreaterOrEqual(PromoteMode::Medium))
		{
			$needCheck = array_unique(array_merge($needCheck, $nonPushOtp));
		}

		$activeSubset = [];
		if (!empty($needCheck))
		{
			$activeSubset = (new \Bitrix\Intranet\Repository\UserRepository())->getActiveConfirmedUserIds($needCheck);
		}

		$enableRequired = array_values(array_intersect(
			array_diff($mandatory, $allActiveOtp),
			$activeSubset,
		));

		$updateRequired = [];
		if ($isMandatoryPush)
		{
			$updateRequired = array_values(array_intersect($nonPushOtp, $mandatory, $activeSubset));
		}

		$updateRecommended = [];
		if ($promoteMode->isGreaterOrEqual(PromoteMode::Medium))
		{
			$updateRecommended = array_values(array_diff(
				array_intersect($nonPushOtp, $activeSubset),
				$updateRequired,
			));
		}

		$enabled = array_values(array_diff($allActiveOtp, $updateRequired, $updateRecommended));

		return [
			UserOtpStatus::Enabled->value => $enabled,
			UserOtpStatus::UpdateRequired->value => $updateRequired,
			UserOtpStatus::UpdateRecommended->value => $updateRecommended,
			UserOtpStatus::EnableRequired->value => $enableRequired,
		];
	}

	private function applyOtpStatusFilter(
		array &$filterValue,
		array $selectedEnums,
		array $statusSets,
	): void
	{
		$hasDisabled = in_array(UserOtpStatus::Disabled, $selectedEnums, true);

		$includeIds = [];
		foreach ($selectedEnums as $status)
		{
			if ($status === UserOtpStatus::Disabled)
			{
				continue;
			}

			$ids = $statusSets[$status->value] ?? [];
			if (!empty($ids))
			{
				$includeIds = array_merge($includeIds, $ids);
			}
		}
		$includeIds = array_unique($includeIds);

		$allActiveOtp = array_merge(
			$statusSets[UserOtpStatus::Enabled->value] ?? [],
			$statusSets[UserOtpStatus::UpdateRequired->value] ?? [],
			$statusSets[UserOtpStatus::UpdateRecommended->value] ?? [],
		);
		$enableRequired = $statusSets[UserOtpStatus::EnableRequired->value] ?? [];
		$disabledExclude = array_unique(array_merge($allActiveOtp, $enableRequired));

		if (!empty($includeIds) && $hasDisabled)
		{
			$filterValue[] = [
				'LOGIC' => 'OR',
				['@ID' => $includeIds],
				['!@ID' => $disabledExclude ?: [0]],
			];
		}
		elseif ($hasDisabled)
		{
			$filterValue[] = ['!@ID' => $disabledExclude ?: [0]];
		}
		elseif (!empty($includeIds))
		{
			$filterValue['@ID'] = $includeIds;
		}
		else
		{
			$filterValue['@ID'] = [0];
		}
	}
}