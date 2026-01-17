<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

class AiAvailabilityService
{
	private const USER_QUERY_CACHE_TTL = 3600;

	public function isAvailableForUser(int $userId): bool
	{
		if (!Loader::includeModule('aiassistant'))
		{
			return false;
		}

		if (!Loader::includeModule('bizproc'))
		{
			return false;
		}

		return $this->userIsReal($userId);
	}

	private function userIsReal(int $userId): bool
	{
		$user = UserTable::query()
			 ->where('ID', $userId)
			 ->where('IS_REAL_USER', true)
			 ->setSelect(['ID'])
			 ->setLimit(1)
			 ->setCacheTtl(self::USER_QUERY_CACHE_TTL)
			 ->fetch()
		;

		return !empty($user);
	}
}