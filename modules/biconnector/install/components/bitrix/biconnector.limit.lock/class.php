<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\LimitManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;
use Bitrix\UI;

class LimitLockComponent extends CBitrixComponent
{
	private LimitManager $limitManager;
	private bool $fullLock = false;

	public function executeComponent()
	{
		if (!$this->includeModules())
		{
			return null;
		}
		$this->init();

		if ($this->limitManager->checkLimitWarning())
		{
			return null;
		}

		$this->prepareResult();
		$this->arResult['TITLE'] = $this->getTitle();
		$this->arResult['LICENSE_BUTTON_TEXT'] = $this->getLicenseButtonText();
		$this->arResult['CONTENT'] = $this->getPopupContent();
		$this->arResult['LICENSE_URL'] = $this->getLicenseUrl();
		$this->arResult['FULL_LOCK'] = $this->fullLock ? 'Y' : 'N';
		$this->arResult['IS_LICENCE_LIMIT'] = $this->limitManager->isLimitByLicence() ? 'Y' : 'N';

		$this->includeComponentTemplate();
	}

	private function init(): void
	{
		$this->limitManager = LimitManager::getInstance();
		$this->fullLock = !$this->limitManager->checkLimit();
	}

	private function prepareResult(): void
	{
		$this->arResult = [
			'TITLE' => '',
			'LICENSE_BUTTON_TEXT' => '',
			'LATER_BUTTON_TEXT' => Loc::getMessage('CC_BLL_LATER_BUTTON_MSGVER_1'),
			'CONTENT' => '',
			'LICENSE_PATH' => '',
		];
	}

	private function getTitle(): string
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Loc::getMessage('CC_BLL_TITLE_BLOCKED');
		}

		if ($this->fullLock)
		{
			return Loc::getMessage('CC_BLL_TITLE_BOX_BLOCKED', [
				'#SHORT_DATE#' => $this->limitManager->getLimitDate()->format(Date::getFormat()),
			]);
		}

		return Loc::getMessage('CC_BLL_TITLE_BOX_WARNING', [
			'#SHORT_DATE#' => $this->limitManager->getLimitDate()->format(Date::getFormat()),
		]);
	}

	private function getLicenseButtonText(): string
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Loc::getMessage('CC_BLL_LICENSE_BUTTON_CLOUD');
		}

		return $this->limitManager->isLimitByLicence() ? Loc::getMessage('CC_BLL_LICENSE_BUTTON_BOX') : '';
	}

	private function getPopupContent(): string
	{
		if (!$this->limitManager->isLimitByLicence())
		{
			return Loc::getMessage('CC_BLL_CONTENT_BLOCKED', [
				'#UNBLOKING_HELP_URL#' => UI\Util::getArticleUrlByCode('26703738'),
			]);
		}

		if ($this->fullLock)
		{
			return Loc::getMessage('CC_BLL_CONTENT_BLOCKED_BOX_MSGVER_2', [
				'#SHORT_DATE#' => $this->limitManager->getLimitDate()->format(Date::getFormat()),
				'#ABOUT_LIMITS_HREF#' => \Bitrix\UI\Util::getArticleUrlByCode('15702822'),
			]);
		}

		return Loc::getMessage('CC_BLL_CONTENT_WARNING_BOX_MSGVER_2', [
			'#SHORT_DATE#' => $this->limitManager->getLimitDate()->format(Date::getFormat()),
			'#ABOUT_LIMITS_HREF#' => \Bitrix\UI\Util::getArticleUrlByCode('15702822'),
		]);
	}

	private function getLicenseUrl(): string
	{
		if (Loader::includeModule('bitrix24'))
		{
			$licenseUrl = \CBitrix24::PATH_LICENSE_ALL;
		}
		else
		{
			$region = \Bitrix\Main\Application::getInstance()->getLicense()->getRegion();
			$licenseUrl = match ($region)
			{
				'ru' => 'https://www.1c-bitrix.ru/buy/products/b24.php',
				'kz' => 'https://www.1c-bitrix.kz/buy/products/b24.php',
				'by' => 'https://www.1c-bitrix.by/buy/products/b24.php',
				'de' => 'https://store.bitrix24.de/profile/license-keys.php',
				default => 'https://store.bitrix24.com/profile/license-keys.php',
			};
		}

		return $licenseUrl;
	}

	private function includeModules(): bool
	{
		if (!Loader::includeModule('biconnector'))
		{
			return false;
		}

		if (!Loader::includeModule('ui'))
		{
			return false;
		}

		return true;
	}
}
