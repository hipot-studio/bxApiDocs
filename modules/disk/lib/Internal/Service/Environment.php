<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Main\Application;

class Environment
{
	public function isCloudPortal(): bool
	{
		return IsModuleInstalled('bitrix24');
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
