<?
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage timeman
 * @copyright 2001-2013 Bitrix
 */


IncludeModuleLangFile(__FILE__);

class CTimemanNotifySchema
{
	public function __construct()
	{
	}

	public static function OnGetNotifySchema()
	{
		return [
			"timeman" => [
				"NAME" => GetMessage("TIMEMAN_NS_GROUP"),
				"NOTIFY" => [
					"entry" => [
						"NAME" => GetMessage("TIMEMAN_NS_ENTRY"),
					],
					"entry_comment" => [
						"NAME" => GetMessage("TIMEMAN_NS_ENTRY_COMMENT"),
					],
					"entry_approve" => [
						"NAME" => GetMessage("TIMEMAN_NS_ENTRY_APPROVE"),
					],
					"report" => [
						"NAME" => GetMessage("TIMEMAN_NS_REPORT"),
						"PUSH" => 'Y',
					],
					"report_comment" => [
						"NAME" => GetMessage("TIMEMAN_NS_REPORT_COMMENT"),
					],
					"report_approve" => [
						"NAME" => GetMessage("TIMEMAN_NS_REPORT_APPROVE"),
					],
				],
			],
		];
	}
}