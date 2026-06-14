<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('CBPCrmContactManualStartTrigger'))
{
	return;
}

class CBPCrmCompanyManualStartTrigger extends \CBPCrmContactManualStartTrigger
{
	protected static function getDocument():string
	{
		return 'COMPANY';
	}

	protected static function resolveDocumentType(): ?array
	{
		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return null;
		}

		return CCrmBizProcHelper::ResolveDocumentType(CCrmOwnerType::Company);
	}
}
