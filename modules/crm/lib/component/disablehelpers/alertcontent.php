<?php

namespace Bitrix\Crm\Component\DisableHelpers;

/**
 * Must correspond to one of the cases in DisableAlert.#getContentByClassName
 */
enum AlertContent: string
{
	case OLD_ENTITY_DISABLE = 'old-entity-disable';
	case OLD_INVOICE_READONLY = 'old-invoice-readonly';
}
