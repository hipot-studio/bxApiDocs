<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage vote
 * @copyright 2001-2025 Bitrix
 */

IncludeModuleLangFile(__FILE__);

class CVoteNotifySchema
{
	public function __construct()
	{
	}

	public static function OnGetNotifySchema()
	{
		return [
			"vote" => [
				"NAME" => GetMessage("V_NS_GROUP"),
				"NOTIFY" => [
					"voting" => [
						"NAME" => GetMessage("V_VOTING"),
					],
				],
			],
		];
	}
}
?>