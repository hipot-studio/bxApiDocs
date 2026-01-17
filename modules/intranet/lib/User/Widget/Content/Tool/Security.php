<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content\Tool;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet;
use Bitrix\Intranet\Internal\Integration;
use Bitrix\Intranet\User;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Security extends BaseTool
{
	public static function isAvailable(User $user): bool
	{
		return self::isOtpEnabled();
	}

	public function getConfiguration(): array
	{
		return [
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_SECURITY_TITLE'),
			'url' => $this->getUrl(),
			'hasCounter' => self::hasCounter(),
			'counterEventName' => self::getPullEventName(),
		];
	}

	public function getName(): string
	{
		return 'security';
	}

	public static function hasCounter(): bool
	{
		if (
			!self::isOtpEnabled()
			|| !Intranet\Internal\Service\Otp\MobilePush::createByDefault()->getPromoteMode()->isGreaterOrEqual(Intranet\Internal\Enum\Otp\PromoteMode::Low)
		)
		{
			return false;
		}

		$user = new Intranet\Entity\User((int)CurrentUser::get()->getId());
		$isPhoneConfirmationRequired = Intranet\Internal\Service\Otp\PersonalMobilePush::isPhoneConfirmationRequiredByUser($user);

		return $isPhoneConfirmationRequired && Intranet\Internal\Service\Otp\PersonalMobilePush::createByUser($user)->isActivated();
	}

	public static function getPullEventName(): string
	{
		return Intranet\Internal\Service\Otp\PersonalMobilePush::PHONE_NUMBER_CONFIRMED_EVENT_NAME;
	}

	private static function isOtpEnabled(): bool
	{
		return (new Integration\Security\OtpSettings())->isEnabled();
	}

	private function getUrl(): string
	{
		$isExtranet = Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();
		$profileLink = $isExtranet ? SITE_DIR . 'contacts/personal' : SITE_DIR . 'company/personal';

		return \CComponentEngine::MakePathFromTemplate(
			$profileLink . '/user/#user_id#/common_security/?page=otpConnected',
			['user_id' => $this->user->getId()],
		);
	}
}
