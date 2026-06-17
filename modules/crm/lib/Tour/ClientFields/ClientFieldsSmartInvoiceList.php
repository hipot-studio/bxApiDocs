<?php

namespace Bitrix\Crm\Tour\ClientFields;

class ClientFieldsSmartInvoiceList extends AbstractClientFieldsEntityList
{
	protected const OPTION_NAME = 'client-fields-smart-invoice-list';

	protected function canShowByEntityTypeId(): bool
	{
		return $this->entityTypeId === \CCrmOwnerType::SmartInvoice;
	}
}
