<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content\Tool;

use Bitrix\Intranet;
use Bitrix\Intranet\Portal;
use Bitrix\Intranet\Internal;
use Bitrix\Intranet\User;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\UserAgent\Platform;

class ApplicationsInstaller extends BaseTool
{
	private bool $mobileInstalled;
	private bool $desktopInstalled;
	private Platform $platform;

	public function __construct(Intranet\User $user)
	{
		parent::__construct($user);
		$platformUsageChecker = new Internal\Service\Platform\UsageChecker();
		$request = Application::getInstance()->getContext()->getRequest();
		$userAgent = (string)$request->getUserAgent();
		$this->platform = Platform::fromUserAgent($userAgent);
		$this->mobileInstalled = $platformUsageChecker->isMobileUsedByUserId($this->user->getId());
		$this->desktopInstalled = $this->platform->isDesktop() && $platformUsageChecker->isPlatformUsedApplication($this->platform, $this->user->getId());
	}

	public static function isAvailable(User $user): bool
	{
		return true;
	}

	public function getConfiguration(): array
	{
		$configuration = [
			'title' => $this->getTitle(),
			'desktop' => [
				'buttonName' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_BUTTON_NAME'),
				'installed' => $this->desktopInstalled,
			],
			'mobile' => [
				'installed' => $this->mobileInstalled,
			],
		];

		if ($this->mobileInstalled && $this->desktopInstalled)
		{
			$configuration['menu'] = [
				...$this->getDesktopDownloadMenuItems(),
				[
					'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_MENU_MOBILE'),
					'type' => 'mobile',
				],
			];
		}

		return $configuration;
	}

	public function getName(): string
	{
		return 'applicationsInstaller';
	}

	private function getDesktopDownloadMenuItems(): array
	{
		$downloadLinks = Portal::getInstance()->getSettings()->getDesktopDownloadLinks();

		if ($this->platform->isLinux())
		{
			return [
				[
					'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_MENU_DESKTOP_LINUX_DEB'),
					'type' => 'desktop',
					'installLink' => $downloadLinks['linuxDeb'],
				],
				[
					'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_MENU_DESKTOP_LINUX_RPM'),
					'type' => 'desktop',
					'installLink' => $downloadLinks['linuxRpm'],
				],
			];
		}

		if ($this->platform === Platform::Macos)
		{
			return [
				[
					'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_MENU_DESKTOP_MACOS_INTEL'),
					'type' => 'desktop',
					'installLink' => $downloadLinks['macos'],
				],
				[
					'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_MENU_DESKTOP_MACOS_ARM'),
					'type' => 'desktop',
					'installLink' => $downloadLinks['macosArm'],
				],
			];
		}

		return [
			[
				'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_MENU_DESKTOP'),
				'type' => 'desktop',
			],
		];
	}

	private function getTitle(): string
	{
		if ($this->desktopInstalled && $this->mobileInstalled)
		{
			return Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_ALL_INSTALLED');
		}

		if ($this->platform->isLinux())
		{
			return $this->desktopInstalled
				? Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_INSTALLED_LINUX')
				: Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_SHOULD_INSTALL_LINUX');
		}

		if ($this->platform === Platform::Macos)
		{
			return $this->desktopInstalled
				? Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_INSTALLED_MACOS')
				: Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_SHOULD_INSTALL_MACOS');
		}

		return $this->desktopInstalled
			? Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_INSTALLED_WINDOWS')
			: Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_TOOL_APPLICATION_INSTALLER_SHOULD_INSTALL_WINDOWS');
	}
}
