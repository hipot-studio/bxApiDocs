<?php

declare(strict_types=1);

use Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScope;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

require_once __DIR__ . '/../crmdynamiccomplexactivity/crmdynamiccomplexactivity.php';

class CBPCrmAutomatedSolutionComplexActivity extends CBPCrmDynamicComplexActivity
{
	protected static function getDynamicTypeScope(): DynamicTypeScope
	{
		return DynamicTypeScope::AutomatedSolution;
	}
}
