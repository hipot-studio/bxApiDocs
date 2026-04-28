<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\Application;

use Bitrix\Market\Internal\Entities\Application\Scope;

class ScopeFactory
{
	public function create(string $scopeKey, string $scopeName): Scope\BasicScope
	{
		if ($scopeKey === 'ai_admin')
		{
			return new Scope\AiAdminScope($scopeKey, $scopeName);
		}

		return new Scope\BasicScope($scopeKey, $scopeName);
	}
}
