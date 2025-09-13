<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Intranet\PortalSettings;
use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Intranet\Util;
use Bitrix\Main\Localization\Loc;

class Desktop extends BaseContent
{
	public function getName(): string
	{
		return 'desktop';
	}

	public function getConfiguration(): array
	{
		$userId = $this->user->getId();
		$installInfo = Util::getAppsInstallationConfig($userId);

		return [
			'downloadLinks' => PortalSettings::getInstance()->getDesktopDownloadLinks(),
			'installInfo' => [
				'windows' => $installInfo['APP_WINDOWS_INSTALLED'] ?? false,
				'mac' => $installInfo['APP_MAC_INSTALLED'] ?? false,
				'linux' => $installInfo['APP_LINUX_INSTALLED'] ?? false,
			],
			'title' => [
				'windows' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_DESKTOP_TITLE_WINDOWS'),
				'mac' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_DESKTOP_TITLE_MAC'),
				'linux' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_DESKTOP_TITLE_LINUX'),
			],
			'linuxMenuTitles' => [
				'deb' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_DESKTOP_LINUX_MENU_TITLE_DEB'),
				'rpm' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_DESKTOP_LINUX_MENU_TITLE_RPM'),
			],
			'linkName' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_DESKTOP_LINK_NAME'),
			'buttonName' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_DESKTOP_BUTTON_NAME'),
			'statusName' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_DESKTOP_STATUS_NAME'),
		];
	}
}
