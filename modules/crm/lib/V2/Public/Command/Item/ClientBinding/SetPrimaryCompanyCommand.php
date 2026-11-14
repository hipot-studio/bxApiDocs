<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Item\ClientBinding;

use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;

/**
 * Makes the bound company primary for the owner item.
 */
final class SetPrimaryCompanyCommand extends AbstractClientBindingCommand
{
	public function __construct(ItemId $ownerId, int $id, int $userId)
	{
		parent::__construct($ownerId, [$id], $userId);
	}

	public function getChildEntityType(): EntityType
	{
		return EntityType::company();
	}
}
