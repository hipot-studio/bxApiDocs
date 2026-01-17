<?php

use Bitrix\Main\Application;

IncludeModuleLangFile(__FILE__);

class CUndo
{
	public static function Add($params = array())
	{
		global $DB, $USER, $CACHE_MANAGER;

		$ID = '1'.md5(uniqid(rand(), true));
		$strContent = serialize($params['arContent']);
		$userID = $USER->GetId();

		$arFields = array(
			'ID' => $ID,
			'MODULE_ID' => $params['module'],
			'UNDO_TYPE' => $params['undoType'],
			'UNDO_HANDLER' => $params['undoHandler'],
			'CONTENT' => $strContent,
			'USER_ID' => $userID,
			'TIMESTAMP_X' => time(),
		);

		$DB->Add("b_undo", $arFields, Array("CONTENT"));

		$CACHE_MANAGER->Clean(mb_substr($ID, 0, 3), "b_undo");

		return $ID;
	}

	public static function Escape($ID)
	{
		global $USER, $CACHE_MANAGER;
		if (!isset($USER) || !is_object($USER) || !$USER->IsAuthorized())
			return false;

		$cacheId = mb_substr($ID, 0, 3);
		if ($CACHE_MANAGER->Read(48 * 3600, $cacheId, "b_undo"))
		{
			$arUndoCache = $CACHE_MANAGER->Get($cacheId);
		}
		else
		{
			$arUndoCache = array();
			$arUndoList = CUndo::GetList(array('arFilter' => array('%ID' => $cacheId."%")));
			foreach ($arUndoList as $ar)
			{
				if (!isset($arUndoCache[$ar["ID"]]) && !isset($arUndoCache[$ar["ID"]][$ar["USER_ID"]]))
					$arUndoCache[$ar["ID"]][$ar["USER_ID"]] = $ar;
			}
			$CACHE_MANAGER->Set($cacheId, $arUndoCache);
		}
		$arUndo = $arUndoCache[$ID][$USER->GetId()] ?? false;

		if (!$arUndo)
			return false;

		// Include module
		if ($arUndo['MODULE_ID'] && $arUndo['MODULE_ID'] <> '')
		{
			if (!CModule::IncludeModule($arUndo['MODULE_ID']))
				return false;
		}

		// Get params for Escaping
		$arParams = unserialize($arUndo['CONTENT'], ['allowed_classes' => false]);

		// Check and call Undo handler
		$p = mb_strpos($arUndo['UNDO_HANDLER'], "::");
		if ($p === false)
		{
			if (function_exists($arUndo['UNDO_HANDLER'])) // function
			{
				call_user_func($arUndo['UNDO_HANDLER'], array($arParams, $arUndo['UNDO_TYPE']));
			}
		}
		else
		{
			$className = mb_substr($arUndo['UNDO_HANDLER'], 0, $p);
			if (class_exists($className)) //class
			{
				$methodName = mb_substr($arUndo['UNDO_HANDLER'], $p + 2);
				if (method_exists($className, $methodName)) //static method
				{
					call_user_func_array(array($className, $methodName), array($arParams, $arUndo['UNDO_TYPE']));
				}
			}
		}

		// Del entry
		CUndo::Delete($ID);

		return true;
	}

	public static function GetList($Params = array())
	{
		global $DB;

		$arFilter = $Params['arFilter'];
		$arOrder = $Params['arOrder'] ?? array('ID' => 'asc');

		$arFields = array(
			"ID" => array("FIELD_NAME" => "U.ID", "FIELD_TYPE" => "string"),
			"MODULE_ID" => array("FIELD_NAME" => "U.MODULE_ID", "FIELD_TYPE" => "string"),
			"UNDO_TYPE" => array("FIELD_NAME" => "U.UNDO_TYPE", "FIELD_TYPE" => "string"),
			"UNDO_HANDLER" => array("FIELD_NAME" => "U.UNDO_HANDLER", "FIELD_TYPE" => "string"),
			"CONTENT" => array("FIELD_NAME" => "U.CONTENT", "FIELD_TYPE" => "string"),
			"USER_ID" => array("FIELD_NAME" => "U.USER_ID", "FIELD_TYPE" => "int"),
			"TIMESTAMP_X" => array("FIELD_NAME" => "U.TIMESTAMP_X", "FIELD_TYPE" => "int"),
		);

		$arSqlSearch = array();

		if (is_array($arFilter))
		{
			foreach ($arFilter as $key => $val)
			{
				$n = mb_strtoupper($key);
				if ($n == '%ID')
					$arSqlSearch[] = "(U.ID like '".$DB->ForSql($val)."')";
				elseif ($n == 'ID' || $n == 'USER_ID')
					$arSqlSearch[] = GetFilterQuery("U.".$n, $val, 'N');
				elseif (isset($arFields[$n]))
					$arSqlSearch[] = GetFilterQuery($arFields[$n]["FIELD_NAME"], $val);
			}
		}

		$strOrderBy = '';
		foreach ($arOrder as $by => $order)
		{
			$by = mb_strtoupper($by);
			if (isset($arFields[$by]))
			{
				$strOrderBy .= $arFields[$by]["FIELD_NAME"].' '.(mb_strtolower($order) == 'desc'? 'desc': 'asc').',';
			}
		}

		if ($strOrderBy)
		{
			$strOrderBy = "ORDER BY ".rtrim($strOrderBy, ",");
		}

		$strSqlSearch = GetFilterSqlSearch($arSqlSearch);
		$strSql = "
			SELECT
				U.*
			FROM
				b_undo U
			WHERE
				$strSqlSearch
			$strOrderBy";

		$res = $DB->Query($strSql);
		$arResult = array();
		while ($arRes = $res->Fetch())
			$arResult[] = $arRes;

		return $arResult;
	}

	public static function Delete($ID)
	{
		global $DB, $CACHE_MANAGER;

		$DB->Query("DELETE FROM b_undo WHERE ID='".$DB->ForSql($ID)."'");

		$CACHE_MANAGER->Clean(mb_substr($ID, 0, 3), "b_undo");
	}

	public static function CleanUpOld()
	{
		global $DB, $CACHE_MANAGER;

		// All entries older than one day
		$timestamp = mktime(date("H"), date("i"), 0, date("m"), date("d") - 1, date("Y"));
		$DB->Query("delete from b_undo where TIMESTAMP_X <= ".$timestamp);

		$CACHE_MANAGER->CleanDir("b_undo");

		return "CUndo::CleanUpOld();";
	}

	public static function ShowUndoMessage($ID)
	{
		Application::getInstance()->getSession()['BX_UNDO_ID'] = $ID;
	}

	public static function CheckNotifyMessage()
	{
		global $USER, $APPLICATION;
		$session = Application::getInstance()->getSession();
		if (!$session->isStarted() || !$session->has('BX_UNDO_ID'))
			return;

		$ID = $session['BX_UNDO_ID'];
		unset($session['BX_UNDO_ID']);

		$arUndoList = CUndo::GetList(array('arFilter' => array('ID' => $ID, 'USER_ID' => $USER->GetId())));
		if (!$arUndoList)
			return;

		$arUndo = $arUndoList[0];
		$detail = GetMessage('MAIN_UNDO_TYPE_'.mb_strtoupper($arUndo['UNDO_TYPE']));

		$s = "
<script>
window.BXUndoLastChanges = function()
{
	if (!confirm(\"".GetMessage("MAIN_UNDO_ESCAPE_CHANGES_CONFIRM")."\"))
		return;

	BX.ajax.get(\"/bitrix/admin/public_undo.php?undo=".$ID."&".bitrix_sessid_get()."\", null, function(result)
	{
		if (result && result.toUpperCase().indexOf(\"ERROR\") != -1)
			BX.admin.panel.Notify(\"".GetMessage("MAIN_UNDO_ESCAPE_ERROR")."\");
		else
			window.location = window.location;
	});
};
BX.ready(function()
{
	setTimeout(function()
	{
		BX.admin.panel.Notify('".$detail." <a href=\"javascript: void(0);\" onclick=\"window.BXUndoLastChanges(); return false;\" title=\"".GetMessage("MAIN_UNDO_ESCAPE_CHANGES_TITLE")."\">".GetMessage("MAIN_UNDO_ESCAPE_CHANGES")."</a>');
	}, 100);
});
</script>";

		$APPLICATION->AddHeadString($s);
	}
}
