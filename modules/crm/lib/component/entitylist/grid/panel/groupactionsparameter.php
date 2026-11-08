<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Panel;

/**
 * Single place that knows under which name a list component reads the flag enabling its group
 * action panel. An unknown component name yields no flag at all: an embedding that cannot be
 * matched here leaves the panel switched off rather than switching it on by guess.
 */
final class GroupActionsParameter
{
	/**
	 * @param string|null $componentName as returned by \Bitrix\Crm\Service\Router::getItemListComponentName()
	 *
	 * @return array parameters to merge into the component parameters, empty for an unknown component
	 */
	public static function forListComponent(?string $componentName): array
	{
		return match ($componentName)
		{
			// camelCase matches the flags crm.item.list reads for itself: isEmbedded, isRecurring
			'bitrix:crm.item.list' => ['enableGroupActions' => true],

			'bitrix:crm.quote.list',
			'bitrix:crm.deal.list',
			'bitrix:crm.lead.list',
			'bitrix:crm.contact.list',
			'bitrix:crm.company.list' => ['ENABLE_GROUP_ACTIONS' => 'Y'],

			default => [],
		};
	}
}
