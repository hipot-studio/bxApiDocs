<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Enum;

enum CrmFormField: string
{
	case UseCrm = 'use_crm';
	case SyncOld = 'crm_sync_old';
	case MaxAge = 'crm_max_age';
	case Public = 'crm_public';
	case AllowEntityIn = 'crm_allow_entity_in';
	case EntityIn = 'crm_entity_in';
	case AllowEntityOut = 'crm_allow_entity_out';
	case EntityOut = 'crm_entity_out';
	case Vcf = 'crm_vcf';
	case LeadSource = 'crm_lead_source';
	case NewLeadFor = 'crm_new_lead_for';
	case Queue = 'crm_queue';
}
