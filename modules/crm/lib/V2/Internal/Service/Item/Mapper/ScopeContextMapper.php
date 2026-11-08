<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Mapper;

use Bitrix\Crm\Service\Context;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\Scope;

/**
 * @internal
 */
class ScopeContextMapper
{
	public static function createContext(AbstractItemCommand $command): Context
	{
		$scopeMap = [
			Scope::Manual->value => Context::SCOPE_MANUAL,
			Scope::Rest->value => Context::SCOPE_REST,
			Scope::Automation->value => Context::SCOPE_AUTOMATION,
			Scope::Import->value => Context::SCOPE_TASK,
			Scope::Ai->value => Context::SCOPE_AI,
			Scope::System->value => Context::SCOPE_TASK,
		];

		$context = new Context();
		$context->setUserId($command->getUserId());
		$context->setScope($scopeMap[$command->getScope()->value] ?? Context::SCOPE_MANUAL);

		return $context;
	}
}
