<?php

use Bitrix\Crm\V2\Infrastructure\Component\MailTemplate\MailTemplateEditorComponent;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

Loader::requireModule('crm');

class CrmMailTemplateEditorComponent extends MailTemplateEditorComponent
{
}
