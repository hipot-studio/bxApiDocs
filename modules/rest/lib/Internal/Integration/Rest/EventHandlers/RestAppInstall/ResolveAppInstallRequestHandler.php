<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Rest\EventHandlers\RestAppInstall;

use Bitrix\Rest\AppTable;
use Bitrix\Rest\Internal\Entity\CurrentUser;
use Bitrix\Rest\Internal\Service\Application\AppInstallRequest\AppInstallRequestManager;

class ResolveAppInstallRequestHandler
{
	public static function handle(array &$fields): void
	{
		if (empty($fields['APP_ID']))
		{
			return;
		}

		$appInfo = AppTable::getByClientId($fields['APP_ID']);
		if (!$appInfo || empty($appInfo['CODE']) || ($appInfo['INSTALLED'] ?? 'N') === 'N')
		{
			return;
		}

		$appCode = (string)$appInfo['CODE'];

		$installerId = (int)(CurrentUser::getInstance()->getId() ?? 0);

		$appVersion = isset($fields['VERSION']) && $fields['VERSION'] !== '' ? (string)$fields['VERSION'] : '1';

		$manager = new AppInstallRequestManager();
		$manager->resolveInstalled($appCode, $appVersion, $installerId);
	}
}
