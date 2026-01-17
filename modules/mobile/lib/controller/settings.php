<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Intranet\ActionFilter\AdminUser;
use Bitrix\Intranet\Service\MobileAppSettings;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Intranet\Dto\EntitySelector\EntitySelectorCodeDto;
use Bitrix\Intranet\Public\Service\OtpSettingsService;
use Bitrix\Mobile\Settings\Dto\SecuritySettingsDto;
use Bitrix\Mobile\Settings\Dto\RightsDto;

final class Settings extends JsonController
{
	public function configureActions(): array
	{
		$commonPrefilters = [
			new CloseSession(),
		];

		$adminPrefilters = [
			new CloseSession(),
			new AdminUser(),
		];

		return [
			'getSecuritySettingsAction' => [
				'+prefilters' => $commonPrefilters,
			],
			'setTakeScreenshotDisabledAction' => [
				'+prefilters' => $adminPrefilters,
			],
			'setCopyTextDisabledAction' => [
				'+prefilters' => $adminPrefilters,
			],
			'setTakeScreenshotRightsAction' => [
				'+prefilters' => $adminPrefilters,
			],
			'setCopyTextRightsAction' => [
				'+prefilters' => $adminPrefilters,
			],
		];
	}

	private static function getMobileAppSettings(): MobileAppSettings
	{

		return \Bitrix\Main\DI\ServiceLocator::getInstance()->get('intranet.option.mobile_app');
	}

	private static function getOtpService(): OtpSettingsService
	{
		return \Bitrix\Main\DI\ServiceLocator::getInstance()->get('intranet.service.settings.otp');
	}

	/**
	 * @restMethod mobile.Settings.getSecuritySettings
	 */
	public function getSecuritySettingsAction(): SecuritySettingsDto
	{
		$mobileAppSettings = self::getMobileAppSettings();
		$userId = $this->getCurrentUser()->getId();
		$otpService = self::getOtpService();

		return SecuritySettingsDto::make([
			'isTakeScreenshotDisabled' => $mobileAppSettings->isTakeScreenshotDisabled(),
			'isCopyTextDisabled' => $mobileAppSettings->isCopyTextDisabled(),
			'isOtpEnabled' => $otpService->isEnabled(),
			'isOtpEnabledForUser' => $otpService->isEnabledForUser($userId),
			'isOtpMandatory' => $otpService->isMandatory(),
			'takeScreenshotRights' => $this->getTakeScreenshotRights(),
			'copyTextRights' => $this->getCopyTextRights($mobileAppSettings),
		]);
	}

	private function getTakeScreenshotRights(): RightsDto
	{
		$rights = self::getMobileAppSettings()->getTakeScreenshotRights();

		return RightsDto::make([
			'isAllUser' => $rights->isAllUser,
			'userIds' => $rights->userIds,
			'departmentIds' => $rights->departmentIds,
			'departmentWithAllChildIds' => $rights->departmentWithAllChildIds,
		]);
	}

	private function getCopyTextRights(MobileAppSettings $settings): RightsDto
	{
		$rights = self::getMobileAppSettings()->getCopyTextRights();

		return RightsDto::make([
			'isAllUser' => $rights->isAllUser,
			'userIds' => $rights->userIds,
			'departmentIds' => $rights->departmentIds,
			'departmentWithAllChildIds' => $rights->departmentWithAllChildIds,
		]);
	}

	/**
	 * @restMethod mobile.Settings.setTakeScreenshotDisabled
	 */
	public function setTakeScreenshotDisabledAction(bool $value): bool
	{
		self::getMobileAppSettings()->setAllowScreenshot(!$value);
		return $value;
	}

	/**
	 * @restMethod mobile.Settings.setTakeScreenshotRights
	 */
	public function setTakeScreenshotRightsAction(array $value): void
	{
		$entitySelectorCodeDto = new EntitySelectorCodeDto(
			$value['isAllUser'] ?? false,
			$value['userIds'] ?? [],
			$value['departmentIds'] ?? [],
			$value['departmentWithAllChildIds'] ?? [],
		);

		self::getMobileAppSettings()->setTakeScreenshotRights($entitySelectorCodeDto->jsonSerialize());
	}

	/**
	 * @restMethod mobile.Settings.setCopyTextDisabled
	 */
	public function setCopyTextDisabledAction(bool $value): bool
	{
		self::getMobileAppSettings()->setAllowCopyText(!$value);
		return $value;
	}

	/**
	 * @restMethod mobile.Settings.setCopyTextRights
	 */
	public function setCopyTextRightsAction(array $value): void
	{
		$entitySelectorCodeDto = new EntitySelectorCodeDto(
			$value['isAllUser'] ?? false,
			$value['userIds'] ?? [],
			$value['departmentIds'] ?? [],
			$value['departmentWithAllChildIds'] ?? [],
		);

		self::getMobileAppSettings()->setCopyTextRights($entitySelectorCodeDto->jsonSerialize());
	}
}