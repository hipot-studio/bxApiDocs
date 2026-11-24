<?php
namespace Bitrix\Im;

class Helper
{
	public static function getOnlineIntranetUsers()
	{
		$onlineSince = \Bitrix\Main\Application::getConnection()->getSqlHelper()->addSecondsToDateTime(
			-\Bitrix\Main\UserTable::getSecondsForLimitOnline()
		);

		$users = \Bitrix\Main\UserTable::query()
			->setSelect(['ID'])
			->where('REAL_USER', 'expr', true)
			->where('LAST_ACTIVITY_DATE', '>', new \Bitrix\Main\DB\SqlExpression($onlineSince))
			->addFilter('!=UF_DEPARTMENT', false)
			->fetchAll()
		;

		return array_map(function($item) { return $item['ID']; }, $users);
	}
}


