<?php
declare(strict_types=1);

use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('CBPCrmContactManualStartTrigger'))
{
	return;
}

class CBPCrmQuoteManualStartTrigger extends \CBPCrmContactManualStartTrigger
{
	protected static function getDocument(): string
	{
		return 'QUOTE';
	}

	protected static function resolveDocumentType(): ?array
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		return CCrmBizProcHelper::ResolveDocumentType(CCrmOwnerType::Quote);
	}
}