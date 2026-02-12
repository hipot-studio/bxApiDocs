<?php

namespace Bitrix\Crm\Component\EntityDetails\TimelineMenuBar;

final class MenuIdResolver
{
	public const SCOPE = 'crm_scope_timeline';

	public static function getMenuId(int $entityTypeId, string $userId, ?int $categoryId = null): string
	{
		return
			(new \Bitrix\Crm\Component\EntityDetails\Config\ScopeIdResolver($entityTypeId, $categoryId))
			->setUserId($userId)
			->getScopeId(self::SCOPE, '_menu')
		;
	}
}