<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
	die;

use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Internal\Access\Otp\UserPermission;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Integration\Main\VerifyPhoneService;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Intranet\Internal\Service\Otp\PersonalMobilePush;
use Bitrix\Intranet\Public\Provider\Otp\DayListProvider;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\PhoneNumber\Format;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Security\Mfa\OtpType;

Loc::loadMessages(__FILE__);

class CSecurityUserOtpConnected extends CBitrixComponent
{
	public function onPrepareComponentParams($arParams)
	{
		$arParams['USER_ID'] = (int)$arParams['USER_ID'];
		$arParams['PATH_TO_CODES'] = '/company/personal/user/' . $arParams['USER_ID'] . '/codes/';

		return $arParams;
	}

	protected function listKeysSignedParameters()
	{
		return [
			'USER_ID', 'PATH_TO_CODES',
		];
	}

	/**
	 * @throws \Bitrix\Main\ObjectNotFoundException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ArgumentTypeException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function executeComponent(): void
	{
		global $USER;

		$userId = (int)$this->arParams["USER_ID"];
		$user = (new UserRepository())->getUserById($userId);

		if (!$user)
		{
			return;
		}

		$permission = new UserPermission($user);

		if (!$permission->canEdit())
		{
			return;
		}

		$otpSettings = new OtpSettings();

		if (!$otpSettings->isAvailable() || !$otpSettings->isEnabled())
		{
			return;
		}

		$verifyPhone = new VerifyPhoneService($user);
		$personalOtp = new PersonalOtp($user);
		$mobilePush = PersonalMobilePush::createByUser($user);

		$this->arResult["OTP"]["IS_ENABLED"] = "Y";
		$this->arResult["OTP"]["IS_MANDATORY"] = !$personalOtp->canSkipMandatory();
		$this->arResult["OTP"]["USER_HAS_EDIT_RIGHTS"] = $USER->CanDoOperation('security_edit_user_otp');

		if (
			Loader::includeModule('bitrix24')
			&& $user->isCurrent()
			&& $user->isIntegrator()
		) {
			$this->arResult["OTP"]["IS_MANDATORY"] = true;
			$this->arResult["OTP"]["USER_HAS_EDIT_RIGHTS"] = false;
		}

		$this->arResult["OTP"]["IS_ACTIVE"] = $personalOtp->isActivated();
		$this->arResult["OTP"]["IS_EXIST"] = $personalOtp->isInitialized();
		$this->arResult["OTP"]["TYPE"] = $personalOtp->getType();
		$this->arResult["OTP"]["DEVICE_INFO"] = $mobilePush->getDeviceInfo();
		$this->arResult["OTP"]["ARE_RECOVERY_CODES_ENABLED"] = $otpSettings->isRecoveredCodesEnabled();
		$this->arResult["OTP"]["CAN_USE_RECOVERED_CODES"] = $personalOtp->isActivated()
			&& $otpSettings->isRecoveredCodesEnabled()
			&& $user->isCurrent();
		$this->arResult['OTP']['PUSH_OTP_CONFIG'] = $personalOtp->getOtpConfig();
		$authPhone = new Phone($user->getAuthPhoneNumber() ?? '');
		$this->arResult['OTP']['PHONE_NUMBER'] = $authPhone->format(Format::INTERNATIONAL);
		$this->arResult['OTP']['PHONE_NUMBER_CONFIRMED'] = $verifyPhone->isConfirmed($authPhone);
		$this->arResult['PROVIDE_SMS_OTP'] = $verifyPhone->canSendSms();

		$dateDeactivate = $personalOtp->getDeactivateUntil();
		$this->arResult["OTP"]["NUM_LEFT_DAYS"] = $dateDeactivate
			? FormatDate('ddiff', time()-60*60*24,  MakeTimeStamp($dateDeactivate) - 1)
			: '';
		$this->arResult["OTP"]["DAY_LIST"] = (new DayListProvider())->getList();

		if ($personalOtp->isPushType() && $personalOtp->isActivated())
		{
			$this->IncludeComponentTemplate('push');
		}
		elseif (
			!$personalOtp->isActivated()
			&& (
				$otpSettings->getDefaultType() === OtpType::Push
				|| $personalOtp->isPushType()
				|| MobilePush::createByDefault()->getPromoteMode()->isGreaterOrEqual(PromoteMode::Low)
			)
		) {
			$this->IncludeComponentTemplate('disabled');
		}
		else
		{
			$this->IncludeComponentTemplate();
		}
	}
}
