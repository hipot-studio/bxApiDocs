<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Internal\Access\Otp\UserPermission;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Integration\Main\VerifyPhoneService;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Intranet\Internal\Service\Otp\PersonalMobilePush;
use Bitrix\Intranet\Public\Provider\Otp\DayListProvider;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use Bitrix\Security\Mfa\OtpType;

class IntranetUserProfileSectionSecurity extends CBitrixComponent
{
	/**
	 * @throws \Bitrix\Main\ObjectNotFoundException
	 */
	public function onPrepareComponentParams($arParams)
	{
		if ((int)$arParams["USER_ID"] <= 0)
		{
			ShowError('Invalid parameter USER_ID');

			return;
		}

		$arParams['USER'] = (new UserRepository())->getUserById((int)$arParams["USER_ID"]);

		if (!$arParams['USER'])
		{
			ShowError('User not found');

			return;
		}

		$arParams['CAN_EDIT'] = (int)CurrentUser::get()->getId() === (int)$arParams["USER_ID"];
		$arParams['PERSONAL_OTP'] = new PersonalOtp($arParams['USER']);

		return $arParams;
	}

	protected function listKeysSignedParameters(): array
	{
		return ['USER_ID'];
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
		$user = $this->arParams['USER'];
		$isAdmin = (new \Bitrix\Intranet\User())->isAdmin();

		if (!Loader::includeModule('security') || (!$user->isCurrent() && !$isAdmin))
		{
			return;
		}

		$this->arResult['IS_CURRENT_USER'] = $user->isCurrent();
		$otpSettings = new OtpSettings();
		$mobilePush = MobilePush::createByDefault();
		$personalOtp = $this->arParams['PERSONAL_OTP'];
		$this->arResult["OTP"]["IS_ENABLED"] = $otpSettings->isEnabled() ? 'Y' : 'N';
		$this->arResult["OTP"]["IS_ACTIVE"] = $personalOtp->isActivated() ? 'Y' : 'N';
		$this->arResult["OTP"]["CAN_EDIT_OTP"] = (new UserPermission($user))->canEdit() ? 'Y' : 'N';
		$this->arResult["CAN_VIEW_RESTORE_PASSWORD"] = false;

		if (
			($mobilePush->getPromoteMode()->isGreaterOrEqual(PromoteMode::Low))
			|| (
				$mobilePush->getPromoteMode() === PromoteMode::Personal
				&& $mobilePush->canUsePersonalModeByUserId((int)$user->getId())
			)
		)
		{
			$personalPushOtp = new PersonalMobilePush($personalOtp);
			$this->arResult["OTP"]["IS_PUSH_OTP_AVAILABLE"] = $user->isCurrent() && !$personalPushOtp->isActivated()  ? 'Y' : 'N';
			$this->arResult["OTP"]["IS_PHONE_CONFIRMATION_REQUIRED"] = $personalPushOtp->isPhoneConfirmationRequired() ? 'Y' : 'N';
			$this->arResult["OTP"]["IS_PUSH_OTP_NEW"] = $this->arResult["OTP"]["IS_PUSH_OTP_AVAILABLE"] && !$personalOtp->isPushType() ? 'Y' : 'N';
		}
		else
		{
			$this->arResult["OTP"]["IS_PUSH_OTP_AVAILABLE"] = 'N';
		}
		$this->arResult["PASSWORD"]["CAN_VIEW"] = $user->isCurrent() || $isAdmin ? 'Y' : 'N';
		$this->arResult["USER"]["CAN_LOGOUT"] = $user->isCurrent() ? 'Y' : 'N';

		$this->arResult['OTP_PARAMS'] = $this->getOtpParameters();
		$this->arResult['IS_CLOUD'] = Loader::includeModule('bitrix24');

		if (
			Loader::includeModule('bitrix24')
			&& Loader::includeModule('socialservices')
			&& $transport = \CBitrix24NetPortalTransport::init()
		) {
			$response = $transport->getProfileContacts($user->getId());
			$this->arResult['PROFILE'] = $response['result'] ?? null;
			if (isset($this->arResult['PROFILE']['PASSWORD_CHANGE_DATE']))
			{
				$this->arResult['PROFILE']['PASSWORD_CHANGE_DATE_FORMATTED'] = $this->formatTs(
					$this->arResult['PROFILE']['PASSWORD_CHANGE_DATE'],
				);
			}
			$networkUri = new Uri( rtrim(CSocServBitrix24Net::NETWORK_URL, '/') . '/passport/view/');
			$this->arResult['PROFILE']['NETWORK_URL'] = $networkUri->getLocator();
			$networkUri->addParams([
				'start_change_password' => 'yes',
			]);
			$this->arResult['PROFILE']['CHANGE_PASSWORD_URL'] = $networkUri->getLocator();
			$this->arResult['CAN_VIEW_RESTORE_PASSWORD'] = !$user->isCurrent()
				&& $user->getInviteStatus() === \Bitrix\Intranet\Enum\InvitationStatus::ACTIVE
				&& (!empty($this->arResult['PROFILE']['EMAIL']) || !empty($this->arResult['PROFILE']['PHONE']))
			;
		}

		$this->IncludeComponentTemplate();
	}

	private function formatTs(int $ts): string
	{
		$culture = Application::getInstance()->getContext()->getCulture();
		$dateFormat = $culture->getShortDateFormat();

		return \Bitrix\Main\Type\DateTime::createFromTimestamp($ts)->toUserTime()->format($dateFormat);
	}

	private function getOtpParameters(): array
	{
		$verifyPhone = new VerifyPhoneService($this->arParams['USER']);
		$pushOtpService = MobilePush::createByDefault();
		$canShowBannerPushOtp = $this->arParams["CAN_EDIT"] && $pushOtpService->getPromoteMode() !== PromoteMode::Disable;

		if (
			$canShowBannerPushOtp
			&& !$pushOtpService->isDefault()
			&& $pushOtpService->getPromoteMode() === PromoteMode::Personal
		)
		{
			$canShowBannerPushOtp = $pushOtpService->canUsePersonalModeByUserId((int)$this->arParams['USER']->getId());
		}

		return [
			...$this->arParams['PERSONAL_OTP']->getOtpConfig(),
			'provideSmsOtp' => $verifyPhone->canSendSms(),
			'canShowBannerPushOtp' => $canShowBannerPushOtp,
			'isOtpActive' => $this->arParams['PERSONAL_OTP']->isActivated(),
			'canDeactivate' => (new UserPermission($this->arParams['USER']))->canDeactivate(),
			'isNotPushOtp' => $this->arParams['PERSONAL_OTP']->getType() !== OtpType::Push,
			'days' => $this->createDays(),
		];
	}

	private function createDays(): array
	{
		return (new DayListProvider())->getList();
	}
}
