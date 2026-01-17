<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true)die();

use Bitrix\Bitrix24\Feature;

use Bitrix\Intranet\Entity\UserOtp;
use Bitrix\Intranet\Internal\Integration;
use Bitrix\Intranet\Internal\Service;
use Bitrix\Intranet\Internal\Enum;
use Bitrix\Intranet\Internal\Access;
use Bitrix\Intranet\User\Access\Model\TargetUserModel;
use Bitrix\Intranet\User\Access\UserAccessController;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Localization\Loc;
use Bitrix\Intranet\Component\UserProfile;
use Bitrix\Main\Loader;
use Bitrix\Main\Error;

Loc::loadMessages(__FILE__);

Loader::includeModule('intranet');

class CIntranetUserProfileComponent extends UserProfile
{
	/** @var \Bitrix\Main\UserField\Dispatcher|null */
	private $userFieldDispatcher = null;

	private function checkRequiredParams()
	{
		if ((int)$this->arParams['ID'] <= 0)
		{
			$this->errorCollection->setError(new Error(Loc::getMessage('INTRANET_USER_PROFILE_NO_USER_ERROR')));
			return false;
		}

		return true;
	}

	public function executeComponent()
	{
		global $APPLICATION, $USER;

		if (!$this->checkRequiredParams())
		{
			$this->printErrors();
			return;
		}

		$isAdminRights = (
			(Loader::includeModule('bitrix24') && \CBitrix24::IsPortalAdmin(\Bitrix\Main\Engine\CurrentUser::get()->getId()))
			|| \Bitrix\Main\Engine\CurrentUser::get()->isAdmin()
		);

		$this->arResult["IS_CURRENT_USER_ADMIN"] = $isAdminRights;

		$this->init();

		$currentUserId = (int) $USER->GetID();
		$ownerUserId = (int) ($this->arParams["ID"] ?? 0);

		$this->arResult["isCloud"] = Loader::includeModule("bitrix24");

		if ($this->arResult["isCloud"])
		{
			$licensePrefix = \CBitrix24::getLicensePrefix();
			$this->arResult["isRusCloud"] = in_array($licensePrefix, array("ru", "by", "kz", "ua"));
			$this->arResult["isAvailableUserLoginHistory"] = Feature::isFeatureEnabled("user_login_history");
			$this->arResult["isConfiguredUserLoginHistory"] = true;
			$this->sendAnalytics();
		}
		else
		{
			$this->arResult["isAvailableUserLoginHistory"] = true;
			$this->arResult["isConfiguredUserLoginHistory"] = \Bitrix\Main\Config\Option::get('main', 'user_device_history', 'N') === 'Y';
		}

		$this->arResult["Urls"] = $this->getUrls();
		$this->arResult["User"] = $this->getUserData();
		$this->arResult["CurrentUser"] = [
			'STATUS' => $this->getCurrentUserStatus()
		];

		$this->userFieldDispatcher = \Bitrix\Main\UserField\Dispatcher::instance();

		$this->arResult["EnablePersonalConfigurationUpdate"] = true;
		$this->arResult["EnableCommonConfigurationUpdate"] = $isAdminRights
			&& $this->arResult["User"]["STATUS"] !== "email";

		$this->arResult["EnableSettingsForAll"] = \Bitrix\Main\Engine\CurrentUser::get()->canDoOperation('edit_other_settings');

		$this->arResult["Permissions"] = $this->getPermissions();

		$this->arResult["UserFieldEntityId"] = "USER";
		$this->arResult["UserFieldPrefix"] = "USR";

		$this->arResult["AllowAllUserProfileFields"] = (
			\Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24')
			|| (
				isset($this->arParams["ALLOWALL_USER_PROFILE_FIELDS"])
				&& $this->arParams["ALLOWALL_USER_PROFILE_FIELDS"] === 'Y'
			)
			|| $this->getTemplateName() === 'widget'
		);

		$this->arResult["EnableUserFieldCreation"] = $this->arResult["EnableCommonConfigurationUpdate"];
		$this->arResult["UserFieldsAvailable"] = $this->getAvailableFields();

		$this->arResult["UserFieldCreateSignature"] = $this->arResult["EnableCommonConfigurationUpdate"]
			? $this->userFieldDispatcher->getCreateSignature(array("ENTITY_ID" => $this->arResult["UserFieldEntityId"]))
			: '';
		$this->arResult["EnableUserFieldMandatoryControl"] = false;
		$this->arResult['SHOW_FACEBOOK_RESTRICTIONS'] = LANGUAGE_ID === 'ru'
			&& method_exists(\Bitrix\Main\Application::getInstance(), 'getLicense')
			&& ($region = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion())
			&& (is_null($region) || mb_strtolower($region) === 'ru');

		if ($this->arResult["User"]["STATUS"] === "email")
		{
			$this->arResult["FormFields"] = $this->getFormInstance()->getFieldInfoForEmailUser();
		}
		else
		{
			$this->arResult["FormFields"] = $this->getFormInstance()->getFieldInfo($this->arResult["User"], [], $this->arParams);

			if (!$this->arResult["isCloud"])
			{
				$this->getFormInstance()->prepareSettingsFields($this->arResult, $this->arParams);
			}
		}

		$this->arResult["FormConfig"] = $this->getFormInstance()->getConfig($this->arResult["SettingsFieldsForConfig"] ?? null);
		$this->arResult["FormData"] = $this->getFormInstance()->getData($this->arResult);

		$this->arResult["Gratitudes"] = $this->getGratsInstance()->getStub();
		$this->arResult["ProfileBlogPost"] = $this->getProfilePostInstance()->getStub();
		$this->arResult["Tags"] = $this->getTagsInstance()->getStub();
		$this->arResult["FormId"] = "intranet-user-profile";
		$this->arResult["IsOwnProfile"] = $currentUserId === $ownerUserId;
		$this->arResult["StressLevel"] = $this->getStressLevelInstance()->getStub();
		$userEntity = \Bitrix\Intranet\Entity\User::initByArray($this->arResult['User']);

		$this->filterHiddenFields();
		$this->checkNumAdminRestrictions();
		$this->prepareOtpInfo($userEntity);

		$this->arResult["isExtranetSite"] = (Loader::includeModule("extranet") && \CExtranet::isExtranetSite());

		$this->arResult["IS_CURRENT_USER_INTEGRATOR"] = false;
		$this->arResult["IS_CURRENT_USER_COLLABER"] = $this->arResult["isExtranetSite"]
			&& \Bitrix\Extranet\Service\ServiceContainer::getInstance()->getCollaberService()->isCollaberById($currentUserId);
		$this->arResult["isFireUserEnabled"] = true;

		if ($this->arResult["isCloud"])
		{
			$this->arResult["IS_CURRENT_USER_INTEGRATOR"] = \Bitrix\Bitrix24\Integrator::isIntegrator($currentUserId);

			if (!Bitrix\Bitrix24\Feature::isFeatureEnabled("user_dismissal"))
			{
				$this->arResult["isFireUserEnabled"] = false;
			}
		}

		$this->arResult['ADDITIONAL_BLOCKS'] = $this->getAdditionalBlocks();
		$this->arResult['IS_ADDITIONAL_BLOCK'] = !empty($this->arResult['ADDITIONAL_BLOCKS']);

		$userService = \Bitrix\Intranet\Service\ServiceContainer::getInstance()->getUserService();
		$access = UserAccessController::createByDefault();
		$userAccessModel = TargetUserModel::createFromUserEntity($userEntity);
		$availableActions = $userService->getAvailableActions($userEntity);
		$availableActions = $access->batchCheck(
			\Bitrix\Intranet\User\Access\UserActionDictionary::valuesForBatchCheck($availableActions),
			$userAccessModel,
		);
		$this->arResult['ACTIONS_AVAILABILITY'] = $availableActions;

		$this->processShowYear();

		$this->arResult["DISK_INFO"] = $this->getDiskInfo();

		if ($this->arResult["Permissions"]['view'])
		{
			$APPLICATION->SetTitle(\CUser::FormatName(\CSite::GetNameFormat(), $this->arResult["User"], true));
		}

		$rootDepartment = \Bitrix\Intranet\Integration\HumanResources\PermissionInvitation::createByCurrentUser()
			->findFirstPossibleAvailableDepartment();
		$this->arResult["ROOT_DEPARTMENT"] = null;
		if ($rootDepartment)
		{
			$this->arResult["ROOT_DEPARTMENT"] = [
				'id' => $rootDepartment->getId(),
				'name' => $rootDepartment->getName(),
				'accessCode' => $rootDepartment->getAccessCode(),
			];
		}

		$this->includeComponentTemplate();
	}

	private function prepareOtpInfo(\Bitrix\Intranet\Entity\User $userEntity): void
	{
		$otpSettings = Loader::includeModule('security') ? new Integration\Security\OtpSettings() : null;

		if (!$otpSettings?->isEnabled())
		{
			$this->arResult["OTP_IS_ENABLED"] = "N";

			return;
		}

		$this->arResult["OTP_IS_ENABLED"] = "Y";
		$otpPermission = new Access\Otp\UserPermission($userEntity);

		if (!$otpPermission->canDeactivate())
		{
			return;
		}

		$personalSettings = $otpSettings?->getPersonalSettingsByUserId($userEntity->getId());
		$otpUser = $personalSettings->getOtpInfo();
		$otpSigner = new Integration\Main\OtpSigner();
		$deactivateStatus = null;
		$deactivateTitle = '';

		if (
			$otpUser->getDeactivateRemainder()
			&& $userEntity->getActive()
			&& Service\Otp\MobilePush::createByDefault()
				->getPromoteMode()
				->isGreaterOrEqual(Enum\Otp\PromoteMode::Low)
		)
		{
			[$deactivateStatus, $deactivateTitle] = $this->getDeactivateOtpStatusText($otpUser);
		}

		$this->arResult['OTP'] = [
			'canEdit' => $otpPermission->canEdit(),
			'canDeactivate' => $otpPermission->canDeactivate(),
			'isActive' => $otpUser->isActive ?? false,
			'isMandatory' => $personalSettings->isRequired(),
			'dateDeactivate' => $otpUser?->dateDeactivate,
			'deactivateStatus' => $deactivateStatus ?? null,
			'signedUserId' => $otpSigner->signUserId($userEntity->getId()),
			'deactivateTitle' => $deactivateTitle,
		];
	}

	private function getDeactivateOtpStatusText(UserOtp $otpUser): ?array
	{
		if ($otpUser->isInitialized)
		{
			return [
				Loc::getMessage('INTRANET_USER_PROFILE_OTP_DEACTIVATE_STATUS', [
					'#REMAINDER#' => $otpUser->getDeactivateRemainder()
				]),
				Loc::getMessage('INTRANET_USER_PROFILE_OTP_DEACTIVATED_MSGVER_1')
			];
		}

		return [
			Loc::getMessage('INTRANET_USER_PROFILE_OTP_DEACTIVATE_STATUS_UNINITIALIZED', [
				'#REMAINDER#' => $otpUser->getDeactivateRemainder()
			]),
			Loc::getMessage('INTRANET_USER_PROFILE_OTP_UNINITIALIZED')
		];
	}

	private function sendAnalytics()
	{
		$event = new AnalyticsEvent('profile_view', 'intranet', 'user_profile');
		$event->setP5('profileId_' . $this->arParams['ID']);
		$event->send();
	}

	private function filterHiddenFields()
	{
		if ($this->arResult["Permissions"]['edit'])
		{
			return;
		}

		if (empty($this->arResult['SettingsFieldsView']))
		{
			return;
		}

		if (empty($this->arResult['SettingsFieldsAll']))
		{
			return;
		}

		$filterFields = array_diff(
			array_column($this->arResult['SettingsFieldsAll'], 'VALUE'),
			array_column($this->arResult['SettingsFieldsView'], 'VALUE')
		);
		$user = $this->arResult["User"];
		foreach ($user as $key => $value)
		{
			if (in_array($key, $filterFields))
			{
				if (is_array($value) && !is_array_assoc($value))
				{
					$value = [];
				}
				else
				{
					$value = '';
				}

				$user[$key] = $value;
			}
		}
		$this->arResult["User"] = $user;
	}
}
