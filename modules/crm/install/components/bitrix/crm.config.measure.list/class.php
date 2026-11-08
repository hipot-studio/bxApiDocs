<?php

use Bitrix\Crm\V2\Infrastructure\Component\Measure\MeasureListComponent;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!Loader::includeModule('crm'))
{
	return;
}

class CrmConfigMeasureListComponent extends MeasureListComponent
{
}
