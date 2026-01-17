<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

use Bitrix\Main\Web\Json;

class CAutoSave
{
	/*'ID', 'COPY_ID', 'ENTITY_ID', 'mid', 'WEB_FORM_ID', 'CONTRACT_ID', 'COURSE_ID', 'IBLOCK_SECTION_ID', 'IBLOCK_ID', 'CHANNEL_ID', 'VOTE_ID', 'DICTIONARY_ID', 'CHAPTER_ID', 'LESSON_ID', */

	private $formId = '';
	private $autosaveId = '';
	private $bInited = false;
	private static $bAllowed = null;
	private static $arImportantParams = array(
		'LANG' => 1,
		'SITE' => 1,
		'PATH' => 1,
		'TYPE' => 1,
		'EVENT_NAME' => 1,
		'SHOW_ERROR' => 1,
		'NAME' => 1,
		'FULL_SRC' => 1,
		'ACTION' => 1,
		'LOGICAL' => 1,
		'ADMIN' => 1,
		'ADDITIONAL' => 1,
		'NEW' => 1,
		'MODE' => 1,
		'CONDITION' => 1,
		'QUESTION_TYPE' => 1,
	);

	public function __construct()
	{
		global $USER;

		if ($USER->IsAuthorized())
		{
			if (isset($_REQUEST['autosave_id']) && mb_strlen($_REQUEST['autosave_id']) == 33)
			{
				$this->autosaveId = preg_replace("/[^a-z0-9_]/i", "", $_REQUEST['autosave_id']);
			}
			else
			{
				$this->formId = self::_GetFormID();
			}

			addEventHandler('main', 'OnBeforeLocalRedirect', array($this, 'Reset'));

			if (!defined('BX_PUBLIC_MODE'))
			{
				CJSCore::Init(array('autosave'));
			}
		}
	}

	public function Init($admin = true)
	{
		global $USER;

		if (!$USER->IsAuthorized())
			return false;

		if (!$this->bInited)
		{
			$DISABLE_STANDARD_NOTIFY = ($admin? 'false': 'true');

			if (defined('BX_PUBLIC_MODE') && BX_PUBLIC_MODE == 1)
				echo CJSCore::GetHTML(array('autosave'));
			?>
			<input type="hidden" name="autosave_id" id="autosave_marker_<?=$this->GetID()?>" value="<?=$this->GetID()?>"/>
			<script>window.autosave_<?=$this->GetID()?> = new top.BX.CAutoSave({
					form_marker: 'autosave_marker_<?=$this->GetID()?>',
					form_id: '<?=$this->GetID()?>',
					DISABLE_STANDARD_NOTIFY: <?=$DISABLE_STANDARD_NOTIFY?>
				});
			</script>
			<?
			$this->checkRestore();

			$this->bInited = true;
		}
		return true;
	}

	public function checkRestore()
	{
		$key = addEventHandler('main', 'OnAutoSaveRestore', array($this, 'Restore'));
		CUndo::Escape($this->GetID());
		removeEventHandler('main', 'OnAutoSaveRestore', $key);
	}

	public function Reset()
	{
		global $USER, $DB, $CACHE_MANAGER;

		if (!$USER->IsAuthorized())
			return false;

		$ID = $this->GetID();
		$DB->Query("DELETE FROM b_undo WHERE ID='".$DB->ForSQL($ID)."' AND USER_ID='".$USER->GetID()."'");

		$CACHE_MANAGER->Clean(mb_substr($ID, 0, 3), "b_undo");

		return true;
	}

	public function Set($data)
	{
		global $DB, $USER, $CACHE_MANAGER;

		if (!$USER->IsAuthorized())
			return false;

		if (!is_array($data) || empty($data))
			return false;

		$ID = $this->GetID();
		$arFields = array(
			'MODULE_ID' => 'main',
			'UNDO_TYPE' => 'autosave',
			'UNDO_HANDLER' => 'CAutoSave::_Restore',
			'CONTENT' => serialize($data),
			'USER_ID' => $USER->GetID(),
			'TIMESTAMP_X' => time(),
		);
		$arBinds = array(
			"CONTENT" => $arFields["CONTENT"],
		);

		$strUpdate = $DB->PrepareUpdate("b_undo", $arFields);
		$rs = $DB->QueryBind("UPDATE b_undo SET ".$strUpdate." WHERE ID = '".$DB->ForSQL($ID)."'", $arBinds);
		if ($rs->AffectedRowsCount() == 0)
		{
			$arFields['ID'] = $ID;
			$DB->Add("b_undo", $arFields, array("CONTENT"), "", true);
		}

		$CACHE_MANAGER->Clean(mb_substr($ID, 0, 3), "b_undo");
		return true;
	}

	public function Restore($arFields)
	{
		if (is_array($arFields))
		{
?>
<script>BX.ready(function(){
	if (window.autosave_<?=$this->GetID();?>)
	{
		window.autosave_<?=$this->GetID();?>.Restore(<?= Json::encode($arFields); ?>);
	}
});</script>
<?
		}
	}

	public function GetID()
	{
		global $USER;

		if (!$this->autosaveId)
		{
			$this->autosaveId = '2'.md5($this->formId.'|'.$USER->GetID());
		}

		return $this->autosaveId;
	}

	private static function _GetFormID()
	{
		global $APPLICATION;

		$arParams = array();
		foreach ($_GET as $param => $value)
		{
			$param = strtoupper($param);

			if (str_ends_with($param, 'ID') || array_key_exists($param, self::$arImportantParams))
				$arParams[$param] = $value;
		}

		ksort($arParams);

		$url = mb_strtolower($APPLICATION->GetCurPage()).'?';
		foreach ($arParams as $param => $value)
		{
			if (is_array($value))
				$value = implode('|', $value);

			$url .= urlencode($param).'='.urlencode($value).'&';
		}

		return $url;
	}

	public static function _Restore($arFields)
	{
		foreach (GetModuleEvents("main", "OnAutoSaveRestore", true) as $arEvent)
		{
			ExecuteModuleEventEx($arEvent, array($arFields));
		}
	}

	public static function Allowed()
	{
		global $USER, $APPLICATION;

		if (!$USER->IsAuthorized())
			return false;

		if (self::$bAllowed == null)
		{
			$arOpt = CUserOptions::GetOption('global', 'settings', []);
			self::$bAllowed = (!isset($arOpt['autosave']) || $arOpt['autosave'] != 'N') && $APPLICATION->GetCurPage() != '/bitrix/admin/update_system.php';
		}

		return self::$bAllowed;
	}
}
