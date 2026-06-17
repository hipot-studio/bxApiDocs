<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Enum;

enum CrmFlag: string
{
	case Preconnect = 'crm_preconnect';
	case Connect = 'crm_connect';
	case PublicBind = 'crm_public_bind';
	case DenyNewLead = 'crm_deny_new_lead';
	case DenyEntityIn = 'crm_deny_entity_in';
	case DenyEntityOut = 'crm_deny_entity_out';
	case DenyNewContact = 'crm_deny_new_contact';

	/**
	 * @return string[]
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
