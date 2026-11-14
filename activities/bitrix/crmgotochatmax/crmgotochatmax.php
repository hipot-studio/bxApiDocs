<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$runtime = CBPRuntime::GetRuntime();
$runtime->IncludeActivityFile('CBPCrmGoToChat');

class CBPCrmGoToChatMax extends CBPCrmGoToChat
{
	protected function getConnectorId()
	{
		return 'max';
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}
}
