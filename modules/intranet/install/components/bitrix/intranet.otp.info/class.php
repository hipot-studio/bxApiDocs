<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\UserOtp;
use Bitrix\Intranet\Internal\Enum\Otp\OtpBannerType;
use Bitrix\Intranet\Internal\Enum\Otp\PromoteMode;
use Bitrix\Intranet\Internal\Factory\Otp\BannerTypeFactory;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Service\Otp\MobilePush;
use Bitrix\Intranet\Portal;
use Bitrix\Main\Application;
use Bitrix\Security\Mfa\OtpType;

class CIntranetOtpInfoComponent extends CBitrixComponent
{
	private const CACHE_TTL = 900;
	private const CACHE_ID_PREFIX = 'otp_banner_type_';

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
		if (!$this->currentUser->isAuthorized() || !$this->otpSettings->isAvailable())
		{
			return;
		}

		$this->arResult['DEFAULT_OTP_TYPE'] = $this->otpSettings->getDefaultType();
		$mobilePush = MobilePush::createByDefault();
		$this->arResult['OLD_OTP_POPUP'] = true;

		if (
			$this->arResult['DEFAULT_OTP_TYPE'] === OtpType::Push
			|| (
				$mobilePush->getPromoteMode()->isGreaterOrEqual(PromoteMode::Medium)
			)
		) {
			$type = $this->getBannerType();

			if (!$type || !$this->checkPopupShowEvents())
			{
				return;
			}

			$this->arResult['OLD_OTP_POPUP'] = false;
			$this->arResult['TRUST_DEVICE_CONFIRMATION'] = $type === OtpBannerType::TRUST_DEVICE_CONFIRMATION;
			$this->arResult['TRUST_PHONE_NUMBER_CONFIRMATION'] = $type === OtpBannerType::TRUST_PHONE_NUMBER_CONFIRMATION;

			if (!$this->arResult['TRUST_DEVICE_CONFIRMATION'])
			{
				$mobilePush = MobilePush::createByDefault();

				$this->arResult['pushOtpConfig'] = [
					'type' => $type->value,
					'gracePeriod' => $this->otpSettings
						->getPersonalSettingsByUserId($this->currentUser->getId())
						?->getGracePeriod()
						?->getTimestamp(),
					'settingsUrl' => Portal::getInstance()->getSettings()->getSettingsUrl(),
					'promoteMode' => $mobilePush->getPromoteMode()->value,
					...($this->otpSettings->getPersonalSettingsByUserId((int)$this->currentUser->getId())?->getOtpConfig() ?? []),
				];
			}
		}
		else
		{
			if (
				!$this->otpSettings->isMandatoryUsing()
				|| $this->isSavedLocalStorageInfo()
				|| !$this->checkPopupShowEvents()
			) {
				return;
			}

			$otpPersonal = $this->otpSettings->getPersonalSettingsByUserId((int)$this->currentUser->getId());

			if (
				$otpPersonal?->isActivated()
				|| !$otpPersonal?->isRequired()
			) {
				return;
			}

			$this->arResult['PATH_TO_PROFILE_SECURITY'] = $this->getPathToProfileSecurity();
			$this->arResult['POPUP_NAME'] = 'otp_mandatory_info';
			$this->arResult['USER']['OTP_DAYS_LEFT'] = $otpPersonal ? $this->getFormattedOtpDaysLeft($otpPersonal->getOtpInfo()) : null;
			$this->saveLocalStorageInfo();
		}

		$this->includeComponentTemplate();
	}

	private function getBannerType(): ?OtpBannerType
	{
		$userId = (int)$this->currentUser->getId();
		$cacheId = self::CACHE_ID_PREFIX . $userId;
		$cacheDir = '/otp/user_id/' . substr(md5((string)$userId), -2) . '/' . $userId . '/';
		$cache = Application::getInstance()->getCache();

		if ($cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			return null;
		}

		$type = (new BannerTypeFactory())->create();

		if ($type === null)
		{
			$cache->startDataCache();
			$cache->endDataCache(true);
		}

		return $type;
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
