<?php

declare(strict_types=1);

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\SettingsHubComponent;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!Loader::includeModule('crm'))
{
	return;
}

class CrmSettingsHubComponent extends SettingsHubComponent
{
}
