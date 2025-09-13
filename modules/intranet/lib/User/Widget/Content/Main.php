<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Bitrix24\Feature;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Intranet\Util;
use Bitrix\Intranet;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use CComponentEngine;
use CFile;
use CSocServBitrix24Net;

class Main extends BaseContent
{
	private CurrentUser $currentUser;

	public function __construct(Intranet\User $user)
	{
		$this->currentUser = CurrentUser::get();
		parent::__construct($user);
	}

	public function getName(): string
	{
		return 'main';
	}

	public function getConfiguration(): array
	{
		return [
			'fullName' => htmlspecialcharsbx($this->currentUser->getFormattedName()),
			'status' => $this->getStatus(),
			'url' => $this->getProfileUrl(),
			'menuItems' => $this->getMenuItems(),
			'userPhotoSrc' => $this->getUserPhotoSrc(),
			'role' => $this->user->getUserRole(),
			'id' => (int)$this->currentUser->getId(),
			'isTimemanAvailable' => self::isTimemanSectionAvailable(),
		];
	}

	public static function isTimemanSectionAvailable(): bool
	{
		return Intranet\Internal\Integration\Timeman\WorkTime::canUse();
	}

	private function getStatus(): string
	{
		$userId = (int)$this->currentUser->getId();
		$status = Util::getUserStatus($userId);
		$workPosition = htmlspecialcharsbx($this->currentUser->getWorkPosition());
		$statusMessage = Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_MAIN_' . $status);

		if ($status && $statusMessage && (!in_array($status, ['employee', 'collaber']) || !$workPosition))
		{
			return $statusMessage;
		}

		if (!empty($workPosition))
		{
			return $workPosition;
		}

		return $statusMessage ?? '';
	}

	public function getUserPhotoSrc(): string
	{
		$userPersonalPhotoSrc = '';
		$userPhotoId = (int)$this->currentUser->getPersonalPhotoId();

		if ($userPhotoId > 0
			&& $this->currentUser->isAuthorized()
			&& ($imageConfig = CFile::ResizeImageGet(
				$userPhotoId,
				[
					'width' => 100,
					'height' => 100,
				],
				BX_RESIZE_IMAGE_EXACT,
			))
			&& is_array($imageConfig)
			&& !empty($imageConfig['src'])
		) {
			$userPersonalPhotoSrc = $imageConfig['src'];
		}

		return (string)$userPersonalPhotoSrc;
	}

	private function getProfileUrl(): string
	{
		$isExtranet = Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();
		$profileLink = $isExtranet ? SITE_DIR . 'contacts/personal' : SITE_DIR . 'company/personal';

		return CComponentEngine::MakePathFromTemplate(
			$profileLink . '/user/#user_id#/',
			['user_id' => $this->currentUser->getId()],
		);
	}

	private function getMenuItems(): array
	{
		$isExtranet = Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();

		$items = [];

		if (!$isExtranet)
		{
			$items[] = $this->getUserLoginHistoryMenuItem();

			if (Intranet\UStat\UStat::checkAvailableCompanyPulse())
			{
				$items[] = [
					'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_MAIN_MORE_MENU_PULSE_TITLE'),
					'icon' => '--o-pulse',
					'type' => 'pulse',
				];
			}
		}

		if (isset($_GET['BXD_API_VERSION']) || str_contains($_SERVER['HTTP_USER_AGENT'], 'BitrixDesktop'))
		{
			$items[] = [
				'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_MAIN_MORE_MENU_DESKTOP_ACCOUNTS_TITLE'),
				'icon' => '--o-group',
				'type' => 'desktop',
			];
		}

		$items[] = $this->getSettingsMenuItem();

		if (
			Loader::includeModule('bitrix24')
			&& Loader::includeModule('socialservices')
			&& Option::get('bitrix24', 'network', 'N') === 'Y'
		) {
			$items[] = [
				'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_MAIN_MORE_MENU_NETWORK_TITLE'),
				'icon' => '--o-cloud',
				'url' => rtrim(CSocServBitrix24Net::NETWORK_URL, '/') . '/passport/view/',
				'type' => 'network',
			];
		}
		elseif ($this->currentUser->isAdmin() && !Loader::includeModule('bitrix24'))
		{
			$items[] = [
				'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_MAIN_MORE_MENU_ADMIN_TITLE'),
				'icon' => '--o-filter-2-lines',
				'url' => '/bitrix/admin/',
				'type' => 'admin',
			];
		}

		return $items;
	}

	private function getSettingsMenuItem(): array
	{
		$isExtranetSite = Loader::includeModule('extranet') && \CExtranet::IsExtranetSite();
		$profileLink = $isExtranetSite ? SITE_DIR . 'contacts/personal' : SITE_DIR . 'company/personal';
		$path = CComponentEngine::MakePathFromTemplate(
			$profileLink . '/user/#user_id#/common_security/?page=auth',
			['user_id' => $this->user->getId()],
		);

		return [
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_MAIN_MORE_MENU_SETTINGS_TITLE'),
			'url' => $path,
			'type' => 'settings',
			'icon' => '--o-settings',
		];
	}

	private function getUserLoginHistoryMenuItem(): array
	{
		if (Loader::includeModule('bitrix24'))
		{
			$isAvailable = Feature::isFeatureEnabled('user_login_history');
			$isConfigured = true;
		}
		else
		{
			$isAvailable = true;
			$isConfigured = Option::get('main', 'user_device_history', 'N') === 'Y';
		}

		return [
			'isAvailable' => $isAvailable,
			'isConfigured' => $isConfigured,
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_MAIN_MORE_MENU_LOGIN_HISTORY_TITLE'),
			'icon' => '--o-bulleted-list',
			'url' => Intranet\Site\Sections\TimemanSection::getUserLoginHistoryUrl(),
			'type' => 'login-history',
		];
	}
}
