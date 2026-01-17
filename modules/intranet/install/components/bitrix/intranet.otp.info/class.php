<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\UserOtp;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Factory\Otp\BannerTypeFactory;
use Bitrix\Intranet\Internal\Integration\Main\OtpSigner;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Intranet\Portal;
use Bitrix\Intranet\Internal\Integration\Security\Otp;
use Bitrix\Security\Mfa\OtpType;

class CIntranetOtpInfoComponent extends CBitrixComponent
{
	private CurrentUser $currentUser;
	private OtpSettings $otpSettings;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->currentUser = Bitrix\Intranet\CurrentUser::get();
		$this->otpSettings = new OtpSettings();
	}

	public function executeComponent(): void
	{
		$this->arResult['DEFAULT_OTP_TYPE'] = $this->otpSettings->getDefaultType();
		$mobilePush = MobilePush::createByDefault();
		$this->arResult['OLD_OTP_POPUP'] = true;

		if (
			$this->arResult['DEFAULT_OTP_TYPE'] === OtpType::Push
			|| (
				$mobilePush->getPromoteMode()->isGreaterOrEqual(PromoteMode::Medium)
			)
		)
		{
			$type = (new BannerTypeFactory())->create();

			if (!$this->currentUser->isAuthorized() || !$type || !$this->checkPopupShowEvents())
			{
				return;
			}

			$this->arResult['OLD_OTP_POPUP'] = false;
			$mobilePush = MobilePush::createByDefault();

			$this->arResult['pushOtpConfig'] = [
				'type' => $type->value,
				'gracePeriod' => $this->otpSettings
					->getPersonalSettingsByUserId($this->currentUser->getId())
					?->getGracePeriod()
					?->getTimestamp(),
				'signedUserId' => (new OtpSigner())->signUserId((int)CurrentUser::get()->getId()),
				'settingsUrl' => Portal::getInstance()->getSettings()->getSettingsUrl(),
				'promoteMode' => $mobilePush->getPromoteMode()->value,
			];
		}
		else
		{
			if (!$this->currentUser->isAuthorized())
			{
				return;
			}

			if (
				!$this->otpSettings->isMandatoryUsing()
				|| $this->isSavedLocalStorageInfo()
				|| !$this->checkPopupShowEvents()
			) {
				return;
			}

			$otpPersonal = $this->otpSettings->getPersonalSettingsByUserId((int)$this->currentUser->getId());

			if (
				$otpPersonal->isActivated()
				|| !$otpPersonal->isRequired()
			) {
				return;
			}

			$this->arResult['PATH_TO_PROFILE_SECURITY'] = $this->getPathToProfileSecurity();
			$this->arResult['POPUP_NAME'] = 'otp_mandatory_info';
			$this->arResult['USER']['OTP_DAYS_LEFT'] = $this->getFormattedOtpDaysLeft($otpPersonal->getOtpInfo());
			$this->saveLocalStorageInfo();
		}

		$this->includeComponentTemplate();
	}

	private function checkPopupShowEvents(): bool
	{
		foreach (GetModuleEvents('intranet', 'OnIntranetPopupShow', true) as $arEvent)
		{
			if (ExecuteModuleEventEx($arEvent) === false)
			{
				return false;
			}
		}

		return true;
	}

	private function isSavedLocalStorageInfo(): bool
	{
		$localStorageInfo = \Bitrix\Main\Application::getInstance()
			->getLocalSession('otpMandatoryInfo')
			->get('otpMandatoryInfo')
		;

		return isset($localStorageInfo);
	}

	private function saveLocalStorageInfo(): void
	{
		\Bitrix\Main\Application::getInstance()
			->getLocalSession('otpMandatoryInfo')
			->set('otpMandatoryInfo', 'Y')
		;
	}

	private function getPathToProfileSecurity(): string
	{
		$this->arParams['PATH_TO_PROFILE_SECURITY'] = trim($this->arParams['PATH_TO_PROFILE_SECURITY'] ?? '');

		if (empty($this->arParams['PATH_TO_PROFILE_SECURITY']))
		{
			$isExtranet = (
				\Bitrix\Main\ModuleManager::isModuleInstalled('extranet')
				&& \COption::getOptionString('extranet', 'extranet_site') === SITE_ID
			);

			$path = $isExtranet ? SITE_DIR . 'contacts/personal' : SITE_DIR . 'company/personal';
			$this->arParams['PATH_TO_PROFILE_SECURITY'] = $path . '/user/#user_id#/security/';
		}

		return \CComponentEngine::MakePathFromTemplate(
			$this->arParams['PATH_TO_PROFILE_SECURITY'],
			['user_id' => $this->currentUser->getId()],
		);
	}

	private function getFormattedOtpDaysLeft(UserOtp $userOtp): string
	{
		if ($userOtp->dateDeactivate)
		{
			$dateDeactivate = MakeTimeStamp($userOtp->dateDeactivate);
		}
		else
		{
			$dateDeactivate = time() + 60 * 60 * 24 * $this->otpSettings->getSkipMandatoryDays();
		}

		return FormatDate('ddiff', time() - 60 * 60 * 24, $dateDeactivate);
	}
}
