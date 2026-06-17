<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Enum;

enum CrmOption: string
{
	case NewEntityIn = 'crm_new_entity_in';
	case NewEntityOut = 'crm_new_entity_out';
	case LeadSource = 'crm_lead_source';
	case LeadResp = 'crm_lead_resp';
	case NewLeadFor = 'crm_new_lead_for';
	case SyncFrom = 'crm_sync_from';
	case SyncDays = 'crm_sync_days';
	case Public = 'crm_public';
	case Vcf = 'crm_vcf';

	/**
	 * @return string[]
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
