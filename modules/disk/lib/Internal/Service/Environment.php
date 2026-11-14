<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class Environment
{
	public function isCloudPortal(): bool
	{
		return IsModuleInstalled('bitrix24');
	}

	public function getPortalZone(): string
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return '';
		}

		return (string)\CBitrix24::getPortalZone();
	}

	public function getLicenseRegion(): ?string
	{
		return Application::getInstance()->getLicense()->getRegion();
	}

	public function getDomain(): string
	{
		if (defined('BX24_HOST_NAME'))
		{
			return BX24_HOST_NAME;
		}

		return Application::getInstance()->getContext()->getRequest()->getHttpHost();
	}
}
