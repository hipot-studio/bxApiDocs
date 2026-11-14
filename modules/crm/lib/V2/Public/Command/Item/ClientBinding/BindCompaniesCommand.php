<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\ClientBinding;

use Bitrix\Crm\V2\Public\EntityType;

/**
 * Binds companies to the owner item.
 */
final class BindCompaniesCommand extends AbstractClientBindingCommand
{
	public function getChildEntityType(): EntityType
	{
		return EntityType::company();
	}
}
