<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Intranet\Internal\Integration\Security;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use CComponentEngine;

class Otp extends BaseContent
{
	public function getName(): string
	{
		return 'otp';
	}

	public function getConfiguration(): array
	{
		if (!self::isAvailable())
		{
			return [
				'isAvailable' => false,
			];
		}

		$isExtranetSite = Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();
		$otp = new Security\Otp();
		$userId = $this->user->getId();
		$profileLink = $isExtranetSite ? SITE_DIR . 'contacts/personal' : SITE_DIR . 'company/personal';
		$path = CComponentEngine::MakePathFromTemplate(
			$profileLink . '/user/#user_id#/common_security/?page=otpConnected',
			['user_id' => $userId],
		);

		return [
			'isAvailable' => true,
			'isActive' => $otp->isActiveByUserId($userId),
			'settingsPath' => $path,
			'settingsButtonTitle' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_OTP_SETTINGS_BUTTON_TITLE'),
			'connectButtonTitle' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_OTP_CONNECT_BUTTON_TITLE'),
			'connectStatus' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_OTP_CONNECT_STATUS'),
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_OTP_TITLE'),
		];
	}

	public static function isAvailable(): bool
	{
		return (new Security\Otp())->isAvailable();
	}
}
