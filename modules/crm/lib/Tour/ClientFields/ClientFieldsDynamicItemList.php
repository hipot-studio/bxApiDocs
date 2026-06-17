<?php

namespace Bitrix\Crm\Tour\ClientFields;

use Bitrix\Crm\Service\Container;

class ClientFieldsDynamicItemList extends AbstractClientFieldsEntityList
{
	protected const OPTION_NAME = 'client-fields-dynamic-item-list';

	protected function canShowByEntityTypeId(): bool
	{
		return
			$this->entityTypeId !== null
			&& \CCrmOwnerType::isPossibleDynamicTypeId($this->entityTypeId)
			&& Container::getInstance()->getFactory($this->entityTypeId)?->isClientEnabled()
		;
	}
}
