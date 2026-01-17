<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Service;

use Bitrix\Intranet\Service\UserService;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class UserUrlService
{
	public function getDetailUrlByUserId(int $userId): string
	{
		if (!Loader::includeModule('intranet'))
		{
			return '';
		}

		return (new UserService())->getDetailUrl($userId);
	}

	public function getDetailUrlTemplate(): string
	{
		if (!Loader::includeModule('intranet'))
		{
			return '';
		}

		return Option::get('intranet', 'path_user', '/company/personal/user/#USER_ID#/', SITE_ID);
	}
}
