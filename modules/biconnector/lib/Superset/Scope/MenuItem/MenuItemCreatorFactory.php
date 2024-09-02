<?php

namespace Bitrix\BIConnector\Superset\Scope\MenuItem;

use Bitrix\BIConnector\Superset\Scope\ScopeService;

final class MenuItemCreatorFactory
{
	public static function getMenuItemCreator(string $scopeCode): ?BaseMenuItemCreator
	{
		if (str_starts_with($scopeCode, ScopeService::BIC_SCOPE_AUTOMATED_SOLUTION_PREFIX))
		{
			return new MenuItemCreatorAutomatedSolution($scopeCode);
		}

		return match ($scopeCode)
		{
			ScopeService::BIC_SCOPE_CRM => new MenuItemCreatorCrm(),
			ScopeService::BIC_SCOPE_TASKS => new MenuItemCreatorTasks(),
			ScopeService::BIC_SCOPE_BIZPROC => new MenuItemCreatorBizproc(),
			default => null,
		};
	}
}
