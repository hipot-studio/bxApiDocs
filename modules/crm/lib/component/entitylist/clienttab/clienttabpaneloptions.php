<?php

namespace Bitrix\Crm\Component\EntityList\ClientTab;

use Bitrix\Crm\ItemIdentifier;

/**
 * What the grid panel of a client tab needs to know about the tab owner.
 */
final class ClientTabPanelOptions
{
	public function __construct(
		public readonly ItemIdentifier $owner,
		public readonly bool $canUpdateOwner,
		public readonly bool $canReadChildType,
	)
	{
	}
}
