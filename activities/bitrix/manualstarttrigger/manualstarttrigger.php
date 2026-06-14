<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPManualStartTrigger extends \Bitrix\Bizproc\Activity\BaseTrigger
{
	public function execute(): int
	{
		return CBPActivityExecutionStatus::Closed;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [];
	}

	public function createApplyRules(): array
	{
		return []; // TODO
	}

	public function checkApplyRules(array $rules, \Bitrix\Bizproc\Activity\Trigger\TriggerParameters $parameters): \Bitrix\Bizproc\Result
	{
		return \Bitrix\Bizproc\Result::createOk();
	}
}
