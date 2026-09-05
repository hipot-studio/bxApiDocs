<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Component\BaseUfComponent;
use Bitrix\UI\UserField\Types\RichTextType;

class RichTextUfComponent extends BaseUfComponent
{
	protected static function getUserTypeId(): string
	{
		return RichTextType::USER_TYPE_ID;
	}
}
