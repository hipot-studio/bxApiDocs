<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\ClientBinding;

use Bitrix\Crm\V2\Public\EntityType;

/**
 * Binds contacts to the owner item.
 */
final class BindContactsCommand extends AbstractClientBindingCommand
{
	public function getChildEntityType(): EntityType
	{
		return EntityType::contact();
	}
}
