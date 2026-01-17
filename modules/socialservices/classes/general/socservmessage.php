<?php

use Bitrix\Main\Application;

class CSocServMessage
{
	protected static function CheckFields($action, &$arFields)
	{
		if(($action == "ADD" && !isset($arFields["SOCSERV_USER_ID"])) || (isset($arFields["SOCSERV_USER_ID"]) && intval($arFields["SOCSERV_USER_ID"])<=0))
		{
			return false;
		}
		if(($action == "ADD" && !isset($arFields["PROVIDER"])) || (isset($arFields["PROVIDER"]) && $arFields["PROVIDER"] == ''))
		{
			return false;
		}
		if($action == "ADD")
			$arFields["INSERT_DATE"] = ConvertTimeStamp(time(), "FULL");
		return true;
	}

	public static function Update($id, $arFields)
	{
		global $DB;
		$id = intval($id);
		if($id<=0 || !self::CheckFields('UPDATE', $arFields))
			return false;
		$strUpdate = $DB->PrepareUpdate("b_socialservices_message", $arFields);
		$strSql = "UPDATE b_socialservices_message SET ".$strUpdate." WHERE ID = ".$id." ";
		$DB->Query($strSql);
		$cache_id = 'socserv_mes_user';
		$obCache = new CPHPCache;
		$cache_dir = '/bx/socserv_mes_user';
		$obCache->Clean($cache_id, $cache_dir);

		return $id;
	}

	public static function Delete($id)
	{
		global $DB;
		$id = intval($id);
		if ($id > 0)
		{
			$rsUser = $DB->Query("SELECT ID FROM b_socialservices_message WHERE ID=".$id);
			$arUser = $rsUser->Fetch();
			if(!$arUser)
				return false;

			$DB->Query("DELETE FROM b_socialservices_message WHERE ID = ".$id." ", true);
			$cache_id = 'socserv_mes_user';
			$obCache = new CPHPCache;
			$cache_dir = '/bx/socserv_mes_user';
			$obCache->Clean($cache_id, $cache_dir);
			return true;
		}
		return false;
	}

	public static function CleanUp()
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$sql = 'DELETE FROM b_socialservices_message WHERE INSERT_DATE < ' . $helper->addDaysToDateTime(-4);
		$connection->query($sql);

		return "CSocServMessage::CleanUp();";
	}

	public static function Add($arFields)
	{
		global $DB;
		if (!self::CheckFields('ADD',$arFields))
			return false;

		$arInsert = $DB->PrepareInsert("b_socialservices_message", $arFields);
		$strSql =
			"INSERT INTO b_socialservices_message (".$arInsert[0].") ".
				"VALUES(".$arInsert[1].")";

		$res=$DB->Query($strSql, true);
		if(!$res)
		{
			return false;
		}
		$lastId = intval($DB->LastID());
		$cache_id = 'socserv_mes_user';
		$obCache = new CPHPCache;
		$cache_dir = '/bx/socserv_mes_user';
		$obCache->Clean($cache_id, $cache_dir);

		return $lastId;
	}

	public static function GetList($arOrder = array(), $arFilter = array(), $arGroupBy = false, $arNavStartParams = false, $arSelectFields = array())
	{
		global $DB;
		if (count($arSelectFields) <= 0)
			$arSelectFields = array("ID", "USER_ID", "SOCSERV_USER_ID", "PROVIDER", "MESSAGE", "INSERT_DATE", "SUCCES_SENT");
		$arFields = array(
			"ID" => array("FIELD" => "SM.ID", "TYPE" => "int"),
			"USER_ID" => array("FIELD" => "SM.USER_ID", "TYPE" => "int"),
			"SOCSERV_USER_ID" => array("FIELD" => "SM.SOCSERV_USER_ID", "TYPE" => "int"),
			"PROVIDER" => array("FIELD" => "SM.PROVIDER", "TYPE" => "string"),
			"MESSAGE" => array("FIELD" => "SM.MESSAGE", "TYPE" => "string"),
			"INSERT_DATE" => array("FIELD" => "SM.INSERT_DATE", "TYPE" => "datetime"),
			"SUCCES_SENT" => array("FIELD" => "SM.SUCCES_SENT", "TYPE" => "char"),
		);
		$arSqls = CGroup::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);
		$arSqls["SELECT"] = str_replace("%%_DISTINCT_%%", "", $arSqls["SELECT"]);

		if (is_array($arGroupBy) && count($arGroupBy)==0)
		{
			$strSql =
				"SELECT ".$arSqls["SELECT"]." ".
					"FROM b_socialservices_message SM ".
					"	".$arSqls["FROM"]." ";
			if ($arSqls["WHERE"] <> '')
				$strSql .= "WHERE ".$arSqls["WHERE"]." ";
			if ($arSqls["GROUPBY"] <> '')
				$strSql .= "GROUP BY ".$arSqls["GROUPBY"]." ";

			$dbRes = $DB->Query($strSql);
			if ($arRes = $dbRes->Fetch())
				return $arRes["CNT"];
			else
				return false;
		}

		$strSql =
			"SELECT ".$arSqls["SELECT"]." ".
				"FROM b_socialservices_message SM ".
				"	".$arSqls["FROM"]." ";
		if ($arSqls["WHERE"] <> '')
			$strSql .= "WHERE ".$arSqls["WHERE"]." ";
		if ($arSqls["GROUPBY"] <> '')
			$strSql .= "GROUP BY ".$arSqls["GROUPBY"]." ";
		if ($arSqls["ORDERBY"] <> '')
			$strSql .= "ORDER BY ".$arSqls["ORDERBY"]." ";
		if (is_array($arNavStartParams) && intval($arNavStartParams["nTopCount"])<=0)
		{
			$strSql_tmp =
				"SELECT COUNT('x') as CNT ".
					"FROM b_socialservices_message SM ".
					"	".$arSqls["FROM"]." ";
			if ($arSqls["WHERE"] <> '')
				$strSql_tmp .= "WHERE ".$arSqls["WHERE"]." ";
			if ($arSqls["GROUPBY"] <> '')
				$strSql_tmp .= "GROUP BY ".$arSqls["GROUPBY"]." ";

			$dbRes = $DB->Query($strSql_tmp);
			$cnt = 0;
			if ($arSqls["GROUPBY"] == '')
			{
				if ($arRes = $dbRes->Fetch())
					$cnt = $arRes["CNT"];
			}
			else
			{
				$cnt = $dbRes->SelectedRowsCount();
			}

			$dbRes = new CDBResult();

			$dbRes->NavQuery($strSql, $cnt, $arNavStartParams);
		}
		else
		{
			if (is_array($arNavStartParams) && intval($arNavStartParams["nTopCount"])>0)
			{
				$strSql = $DB->TopSql($strSql, $arNavStartParams["nTopCount"]);
			}

			$dbRes = $DB->Query($strSql);
		}

		return $dbRes;
	}
}
