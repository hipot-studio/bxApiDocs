<?php

use Bitrix\Ldap\EncryptionType;
use Bitrix\Ldap\Internal\Models\SyncSessionTable;
use Bitrix\Ldap\Internal\Security\Encryption;
use Bitrix\Ldap\Internal\User;
use Bitrix\Ldap\Sync\Logger;
use Bitrix\Ldap\Sync\SessionManager;
use Bitrix\Ldap\Sync\Stepper;

IncludeModuleLangFile(__FILE__);

class CLdapServer
{
	public $arFields = array();
	public static $syncErrors = array();

	// region CRUD

	/**
	 * @param array $arOrder
	 * @param array $arFilter
	 * @return __CLDAPServerDBResult
	 */
	public static function GetList($arOrder=Array(), $arFilter=Array())
	{
		global $DB;
		$strSql =
				"SELECT ls.*, ".
				"	".$DB->DateToCharFunction("ls.TIMESTAMP_X")."	as TIMESTAMP_X, ".
				"	".$DB->DateToCharFunction("ls.SYNC_LAST")."	as SYNC_LAST ".
				"FROM b_ldap_server ls ";

		if(!is_array($arFilter))
			$arFilter = Array();

		$arSqlSearch = Array();
		$filter_keys = array_keys($arFilter);
		$fkCount = count($filter_keys);

		for($i=0; $i<$fkCount; $i++)
		{
			$val = $arFilter[$filter_keys[$i]];
			$key=$filter_keys[$i];
			$res = self::MkOperationFilter((string)$key);
			$key = mb_strtoupper($res["FIELD"]);
			$cOperationType = $res["OPERATION"];
			switch($key)
			{
				case "ACTIVE":
				case "SYNC":
				case "CONVERT_UTF8":
				case "USER_GROUP_ACCESSORY":
					$arSqlSearch[] = self::FilterCreate("ls.".$key, $val, "string_equal", $cOperationType);
					break;
				case "ID":
				case "PORT":
				case "MAX_PAX_SIZE":
				case "CONNECTION_TYPE":
					$arSqlSearch[] = self::FilterCreate("ls.".$key, $val, "number", $cOperationType);
					break;
				case "SYNC_LAST":
				case "TIMESTAMP_X":
					$arSqlSearch[] = self::FilterCreate("ls.".$key, $val, "date", $cOperationType);
					break;
				case "CODE":
				case "NAME":
				case "DESCRIPTION":
				case "SERVER":
				case "ADMIN_LOGIN":
				case "ADMIN_PASSWORD":
				case "BASE_DN":
				case "GROUP_FILTER":
				case "GROUP_ID_ATTR":
				case "GROUP_NAME_ATTR":
				case "GROUP_MEMBERS_ATTR":
				case "USER_FILTER":
				case "USER_ID_ATTR":
				case "USER_NAME_ATTR":
				case "USER_LAST_NAME_ATTR":
				case "USER_EMAIL_ATTR":
				case "USER_GROUP_ATTR":
					$arSqlSearch[] = self::FilterCreate("ls.".$key, $val, "string", $cOperationType);
					break;
			}
		}

		$is_filtered = false;
		$strSqlSearch = "";

		for($i=0, $ssCount=count($arSqlSearch); $i<$ssCount; $i++)
		{
			if($arSqlSearch[$i] <> '')
			{
				$is_filtered = true;
				$strSqlSearch .= " AND  (".$arSqlSearch[$i].") ";
			}
		}

		$arSqlOrder = Array();
		foreach($arOrder as $by=>$order)
		{
			$order = mb_strtolower($order) === 'asc' ? 'asc' : 'desc';

			switch(mb_strtoupper($by))
			{
				case "ID":
				case "NAME":
				case "CODE":
				case "ACTIVE":
				case "CONVERT_UTF8":
				case "SERVER":
				case "PORT":
				case "ADMIN_LOGIN":
				case "ADMIN_PASSWORD":
				case "BASE_DN":
				case "GROUP_FILTER":
				case "SYNC":
				case "SYNC_LAST":
				case "GROUP_ID_ATTR":
				case "GROUP_NAME_ATTR":
				case "GROUP_MEMBERS_ATTR":
				case "USER_FILTER":
				case "USER_ID_ATTR":
				case "USER_NAME_ATTR":
				case "USER_LAST_NAME_ATTR":
				case "USER_EMAIL_ATTR":
				case "USER_GROUP_ATTR":
				case "USER_GROUP_ACCESSORY":
				case "MAX_PAX_SIZE":
				case "CONNECTION_TYPE":
					$arSqlOrder[] = " ls.".$by." ".$order." ";
					break;
				default:
					$arSqlOrder[] = " ls.TIMESTAMP_X ".$order." ";
			}
		}

		$strSqlOrder = "";
		DelDuplicateSort($arSqlOrder);

		for ($i=0, $c=count($arSqlOrder); $i < $c; $i++)
		{
			if($i==0)
				$strSqlOrder = " ORDER BY ";
			else
				$strSqlOrder .= ",";

			$strSqlOrder .= mb_strtolower($arSqlOrder[$i]);
		}

		$strSql .= " WHERE 1=1 ".$strSqlSearch.$strSqlOrder;

		$res = $DB->Query($strSql);
		$res = new __CLDAPServerDBResult($res);
		return $res;
	}

	/**
	 * @param int $ID
	 * @return __CLDAPServerDBResult
	 */
	public static function GetByID($ID)
	{
		return CLdapServer::GetList(Array(), $arFilter=Array("ID"=>intval($ID)));
	}

	/**
	 * @param array $arFields
	 * @return int|false
	 */
	public static function Add($arFields)
	{
		global $DB, $APPLICATION;
		$APPLICATION->ResetException();

		$encryptionType = EncryptionType::tryFrom((int)$arFields['CONNECTION_TYPE']) ?? EncryptionType::None;

		if(is_set($arFields, "ACTIVE") && $arFields["ACTIVE"]!="Y")
			$arFields["ACTIVE"]="N";

		if(is_set($arFields, "SYNC") && $arFields["SYNC"]!="Y")
			$arFields["SYNC"]="N";

		if(is_set($arFields, "CONVERT_UTF8") && $arFields["CONVERT_UTF8"]!="Y")
			$arFields["CONVERT_UTF8"]="N";

		if(is_set($arFields, "USER_GROUP_ACCESSORY") && $arFields["USER_GROUP_ACCESSORY"]!="Y")
			$arFields["USER_GROUP_ACCESSORY"]="N";

		$arFields['PORT'] = empty($arFields['PORT']) ? (string)$encryptionType->port() : $arFields['PORT'];

		if(!self::CheckFields($arFields))
			return false;

		if(is_set($arFields, "ADMIN_PASSWORD"))
			$arFields["ADMIN_PASSWORD"] = Encryption::encrypt($arFields["ADMIN_PASSWORD"]);

		if(is_set($arFields, "FIELD_MAP") && is_array($arFields["FIELD_MAP"]))
		{
			$arFields["USER_NAME_ATTR"] = "".$arFields["FIELD_MAP"]["NAME"];
			$arFields["USER_LAST_NAME_ATTR"] = "".$arFields["FIELD_MAP"]["LAST_NAME"];
			$arFields["USER_EMAIL_ATTR"] = "".$arFields["FIELD_MAP"]["EMAIL"];

			$arFields["FIELD_MAP"] = serialize($arFields["FIELD_MAP"]);
		}

		$ID = $DB->Add("b_ldap_server", $arFields);

		if(is_set($arFields, 'GROUPS'))
			CLdapServer::SetGroupMap($ID, $arFields['GROUPS']);

		if($arFields["SYNC"]=="Y")
			CLdapServer::__UpdateAgentPeriod($ID, $arFields["SYNC_PERIOD"]);

		return $ID;
	}

	/**
	 * @param int $ID
	 * @param array $arFields
	 * @return bool
	 */
	public static function Update($ID, $arFields)
	{
		global $DB, $APPLICATION;
		$APPLICATION->ResetException();

		$ID = intval($ID);

		if(is_set($arFields, "ACTIVE") && $arFields["ACTIVE"]!="Y")
			$arFields["ACTIVE"]="N";

		if(is_set($arFields, "SYNC") && $arFields["SYNC"]!="Y")
			$arFields["SYNC"]="N";

		if(is_set($arFields, "SYNC_USER_ADD") && $arFields["SYNC_USER_ADD"] != "Y")
			$arFields["SYNC_USER_ADD"] = "N";

		if(is_set($arFields, "CONVERT_UTF8") && $arFields["CONVERT_UTF8"]!="Y")
			$arFields["CONVERT_UTF8"]="N";

		if(is_set($arFields, "USER_GROUP_ACCESSORY") && $arFields["USER_GROUP_ACCESSORY"]!="Y")
			$arFields["USER_GROUP_ACCESSORY"]="N";

		if(is_set($arFields, "IMPORT_STRUCT") && $arFields["IMPORT_STRUCT"]!="Y")
			$arFields["IMPORT_STRUCT"]="N";

		if(is_set($arFields, "STRUCT_HAVE_DEFAULT") && $arFields["STRUCT_HAVE_DEFAULT"]!="Y")
			$arFields["STRUCT_HAVE_DEFAULT"]="N";

		if(is_set($arFields, "SET_DEPARTMENT_HEAD") && $arFields["SET_DEPARTMENT_HEAD"]!="Y")
			$arFields["SET_DEPARTMENT_HEAD"]="N";

		if (empty($arFields['PORT']) && isset($arFields['CONNECTION_TYPE']))
		{
			$encryptionType = EncryptionType::tryFrom((int)$arFields['CONNECTION_TYPE']) ?? EncryptionType::None;
			$arFields['PORT'] = (string)$encryptionType->port();
		}

		if(!self::CheckFields($arFields, $ID))
			return false;

		if(is_set($arFields, "ADMIN_PASSWORD"))
			$arFields["ADMIN_PASSWORD"] = Encryption::encrypt($arFields["ADMIN_PASSWORD"]);

		if(is_set($arFields, "FIELD_MAP") && is_array($arFields["FIELD_MAP"]))
		{
			$arFields["USER_NAME_ATTR"] = "".$arFields["FIELD_MAP"]["NAME"];
			$arFields["USER_LAST_NAME_ATTR"] = "".$arFields["FIELD_MAP"]["LAST_NAME"];
			$arFields["USER_EMAIL_ATTR"] = "".$arFields["FIELD_MAP"]["EMAIL"];

			$arFields["FIELD_MAP"] = serialize($arFields["FIELD_MAP"]);
		}

		if(isset($arFields["SYNC"]) || isset($arFields["SYNC_PERIOD"]))
		{
			$dbld = CLdapServer::GetById($ID);
			$arLdap = $dbld->Fetch();
		}

		$arFields['~TIMESTAMP_X'] = $DB->CurrentTimeFunction();

		$strUpdate = $DB->PrepareUpdate("b_ldap_server", $arFields);

		$strSql =
			"UPDATE b_ldap_server SET ".
				$strUpdate." ".
			"WHERE ID=".$ID;

		$DB->Query($strSql);

		if(is_set($arFields, 'GROUPS'))
			CLdapServer::SetGroupMap($ID, $arFields['GROUPS']);

		if(isset($arFields["SYNC"]) || isset($arFields["SYNC_PERIOD"]))
		{
			if($arLdap)
			{
				if(isset($arFields["SYNC"]))
				{
					if($arFields["SYNC"]!="Y" && $arLdap["SYNC"]=="Y")
						CLdapServer::__UpdateAgentPeriod($ID, 0);
					elseif($arFields["SYNC"]=="Y" && $arLdap["SYNC"]!="Y")
						CLdapServer::__UpdateAgentPeriod($ID, (isset($arFields["SYNC_PERIOD"])? $arFields["SYNC_PERIOD"] : $arLdap["SYNC_PERIOD"]));
					elseif(isset($arFields["SYNC_PERIOD"]) && $arLdap["SYNC_PERIOD"]!=$arFields["SYNC_PERIOD"])
						CLdapServer::__UpdateAgentPeriod($ID, $arFields["SYNC_PERIOD"]);
				}
				elseif($arLdap["SYNC_PERIOD"]!=$arFields["SYNC_PERIOD"])
					CLdapServer::__UpdateAgentPeriod($ID, $arFields["SYNC_PERIOD"]);
			}
		}

		return true;
	}

	/**
	 * @param int $ID
	 * @return CDBResult|false
	 */
	public static function Delete($ID)
	{
		global $DB;
		$ID = intval($ID);

		$strSql = "DELETE FROM b_ldap_group WHERE LDAP_SERVER_ID=".$ID;
		if(!$DB->Query($strSql, true))
			return false;

		$strSql = "DELETE FROM b_ldap_server WHERE ID=".$ID;
		return $DB->Query($strSql, true);
	}

	/**
	 * @deprecated Will be private soon.
	 * @param array $arFields
	 * @param int|bool $ID
	 * @return bool
	 */
	public static function CheckFields($arFields, $ID=false)
	{
		$arMsg = Array();

		if(($ID===false || is_set($arFields, "NAME")) && mb_strlen($arFields["NAME"]) < 1)
			$arMsg[] = array("id"=>"NAME", "text"=> GetMessage("LDAP_ERR_EMPTY")." ".GetMessage("LDAP_ERR_NAME"));

		if(($ID===false || is_set($arFields, "SERVER")) && mb_strlen($arFields["SERVER"]) < 1)
			$arMsg[] = array("id"=>"SERVER", "text"=> GetMessage("LDAP_ERR_EMPTY")." ".GetMessage("LDAP_ERR_SERVER"));

		if(($ID===false || is_set($arFields, "PORT")) && mb_strlen($arFields["PORT"]) < 1)
			$arMsg[] = array("id"=>"PORT", "text"=> GetMessage("LDAP_ERR_EMPTY")." ".GetMessage("LDAP_ERR_PORT"));

		if(($ID===false || is_set($arFields, "BASE_DN")) && mb_strlen($arFields["BASE_DN"]) < 1)
			$arMsg[] = array("id"=>"BASE_DN", "text"=> GetMessage("LDAP_ERR_EMPTY")." ".GetMessage("LDAP_ERR_BASE_DN"));

		if(($ID===false || is_set($arFields, "GROUP_FILTER")) && mb_strlen($arFields["GROUP_FILTER"]) < 1)
			$arMsg[] = array("id"=>"GROUP_FILTER", "text"=> GetMessage("LDAP_ERR_EMPTY")." ".GetMessage("LDAP_ERR_GROUP_FILT"));

		if(($ID===false || is_set($arFields, "GROUP_ID_ATTR")) && mb_strlen($arFields["GROUP_ID_ATTR"]) < 1)
			$arMsg[] = array("id"=>"GROUP_ID_ATTR", "text"=> GetMessage("LDAP_ERR_EMPTY")." ".GetMessage("LDAP_ERR_GROUP_ATTR"));

		if(($ID===false || is_set($arFields, "USER_FILTER")) && mb_strlen($arFields["USER_FILTER"]) < 1)
			$arMsg[] = array("id"=>"USER_FILTER", "text"=> GetMessage("LDAP_ERR_EMPTY")." ".GetMessage("LDAP_ERR_USER_FILT"));

		if(($ID===false || is_set($arFields, "USER_ID_ATTR")) && mb_strlen($arFields["USER_ID_ATTR"]) < 1)
			$arMsg[] = array("id"=>"USER_ID_ATTR", "text"=> GetMessage("LDAP_ERR_EMPTY")." ".GetMessage("LDAP_ERR_USER_ATTR"));

		if(!empty($arMsg))
		{
			$e = new CAdminException($arMsg);
			$GLOBALS["APPLICATION"]->ThrowException($e);
			return false;
		}

		return true;
	}

	private static function MkOperationFilter(string $key): array
	{
		if(mb_substr($key, 0, 1) == "!")
		{
			$key = mb_substr($key, 1);
			$cOperationType = "N";
		}
		elseif(mb_substr($key, 0, 1) == "?")
		{
			$key = mb_substr($key, 1);
			$cOperationType = "?";
		}
		elseif(mb_substr($key, 0, 2) == ">=")
		{
			$key = mb_substr($key, 2);
			$cOperationType = "GE";
		}
		elseif(mb_substr($key, 0, 1) == ">")
		{
			$key = mb_substr($key, 1);
			$cOperationType = "G";
		}
		elseif(mb_substr($key, 0, 2) == "<=")
		{
			$key = mb_substr($key, 2);
			$cOperationType = "LE";
		}
		elseif(mb_substr($key, 0, 1) == "<")
		{
			$key = mb_substr($key, 1);
			$cOperationType = "L";
		}
		else
			$cOperationType = "E";

		return Array("FIELD"=>$key, "OPERATION"=>$cOperationType);
	}

	private static function FilterCreate($fname, $vals, $type, $cOperationType=false, $bSkipEmpty = true)
	{
		return self::FilterCreateEx($fname, $vals, $type, $bFullJoin, $cOperationType, $bSkipEmpty);
	}

	private static function FilterCreateEx($fname, $vals, $type, &$bFullJoin, $cOperationType=false, $bSkipEmpty = true)
	{
		global $DB;
		if(!is_array($vals))
			$vals=Array($vals);

		if(count($vals)<1)
			return "";
		if(is_bool($cOperationType))
		{
			if($cOperationType===true)
				$cOperationType = "N";
			else
				$cOperationType = "E";
		}

		if($cOperationType=="G")
			$strOperation = ">";
		elseif($cOperationType=="GE")
			$strOperation = ">=";
		elseif($cOperationType=="LE")
			$strOperation = "<=";
		elseif($cOperationType=="L")
			$strOperation = "<";
		else
			$strOperation = "=";

		$bFullJoin = false;
		$bWasLeftJoin = false;

		$res = Array();
		for($i=0, $c=count($vals); $i < $c; $i++)
		{
			$val = $vals[$i];
			if(!$bSkipEmpty || $val <> '' || (is_bool($val) && $val===false))
			{
				switch ($type)
				{
					case "string_equal":
						if($cOperationType=="?")
						{
							if($val <> '')
								$res[] = GetFilterQuery($fname, $val, "N");
						}
						else
						{
							if($val == '')
								$res[] = ($cOperationType=="N"?"NOT":"")."(".$fname." IS NULL OR ".$DB->Length($fname)."<=0)";
							else
								$res[] = ($cOperationType=="N"?" ".$fname." IS NULL OR NOT ":"")."(".self::_Upper($fname).$strOperation.self::_Upper("'".$DB->ForSql($val)."'").")";
						}
						break;
					case "string":
						if($cOperationType=="?")
						{
							if($val <> '')
							{
								$sr = GetFilterQuery($fname, $val, "Y", array(), "N");
								if($sr != "0")
									$res[] = $sr;
							}
						}
						else
						{
							if($val == '')
								$res[] = ($cOperationType=="N"?"NOT":"")."(".$fname." IS NULL OR ".$DB->Length($fname)."<=0)";
							else
								if($strOperation=="=")
									$res[] = ($cOperationType == "N" ? " " . $fname . " IS NULL OR NOT " : "") . "(" . $fname . " LIKE '" . $DB->ForSqlLike($val) . "')";
								else
									$res[] = ($cOperationType == "N" ? " " . $fname . " IS NULL OR NOT " : "") . "(" . $fname . " " . $strOperation . " '" . $DB->ForSql($val) . "')";
						}
						break;
					case "date":
						if($val == '')
							$res[] = ($cOperationType=="N"?"NOT":"")."(".$fname." IS NULL)";
						else
							$res[] = ($cOperationType=="N"?" ".$fname." IS NULL OR NOT ":"")."(".$fname." ".$strOperation." ".$DB->CharToDateFunction($DB->ForSql($val), "FULL").")";
						break;
					case "number":
						if($cOperationType=="?")
						{
							$sqlHelper = \Bitrix\Main\Application::getConnection()->getSqlHelper();

							$res[] = "(" . $sqlHelper->castToChar($fname) . " LIKE '%" . $DB->ForSqlLike(trim($val)) . "%' AND " . $fname . " IS NOT NULL)";
						}
						else
						{
							if($val == '')
								$res[] = ($cOperationType=="N"?"NOT":"")."(".$fname." IS NULL)";
							else
								$res[] = ($cOperationType=="N"?" ".$fname." IS NULL OR NOT ":"")."(".$fname." ".$strOperation." '".DoubleVal($val)."')";
						}
						break;
					case "number_above":
						if($val == '')
							$res[] = ($cOperationType=="N"?"NOT":"")."(".$fname." IS NULL)";
						else
							$res[] = ($cOperationType=="N"?" ".$fname." IS NULL OR NOT ":"")."(".$fname." ".$strOperation." '".$DB->ForSql($val)."')";
						break;
				}

				// we need this conditions to do INNER JOIN
				if($val <> '' && $cOperationType!="N")
					$bFullJoin = true;
				else
					$bWasLeftJoin = true;
			}
		}

		$strResult = "";
		for($i=0, $c=count($res); $i < $c; $i++)
		{
			if($i>0)
				$strResult .= ($cOperationType=="N"?" AND ":" OR ");
			$strResult .= "(".$res[$i].")";
		}
		if($strResult!="")
			$strResult = "(".$strResult.")";

		if($bFullJoin && $bWasLeftJoin && $cOperationType!="N")
			$bFullJoin = false;

		return $strResult;
	}

	private static function _Upper($str)
	{
		global $DB;
		return ($DB->type === 'PGSQL' ? 'UPPER(' . $str . ')' : $str);
	}

	// endregion

	// region group management

	public static function GetGroupMap($ID)
	{
		global $DB;
		$ID = intval($ID);
		return $DB->Query("SELECT GROUP_ID, LDAP_GROUP_ID FROM b_ldap_group WHERE LDAP_SERVER_ID=".$ID." AND NOT (GROUP_ID=-1)");
	}

	public static function GetGroupBan($ID)
	{
		global $DB;
		$ID = intval($ID);
		return $DB->Query("SELECT LDAP_GROUP_ID FROM b_ldap_group WHERE LDAP_SERVER_ID=".$ID." AND GROUP_ID=-1");
	}

	public static function SetGroupMap($ID, $arFields)
	{
		global $DB;
		$ID = intval($ID);
		$DB->Query("DELETE FROM b_ldap_group WHERE LDAP_SERVER_ID=".$ID);
		foreach($arFields as $arGroup)
		{
			// check whether entry is valid, and if it is - add it
			if(array_key_exists('GROUP_ID',$arGroup) && ($arGroup['GROUP_ID']>0 || $arGroup['GROUP_ID']==-1) && $arGroup['LDAP_GROUP_ID'] <> '')
			{
				$strSql =
					"SELECT 'x' ".
					"FROM b_ldap_group ".
					"WHERE LDAP_SERVER_ID=".$ID." ".
					"	AND GROUP_ID = ".intval($arGroup['GROUP_ID'])." ".
					"	AND LDAP_GROUP_ID = '".$DB->ForSQL($arGroup['LDAP_GROUP_ID'], 255)."' ";
				$r = $DB->Query($strSql);
				if(!$r->Fetch())
				{
					$strSql =
						"INSERT INTO b_ldap_group(GROUP_ID, LDAP_GROUP_ID, LDAP_SERVER_ID)".
						"VALUES(".intval($arGroup['GROUP_ID']).", '".$DB->ForSQL($arGroup['LDAP_GROUP_ID'], 255)."', ".$ID.")";
					$DB->Query($strSql);
				}
			}
		}
	}

	public static function isUserInBannedGroups($ldap_server_id, $arUserFields)
	{
		static $noImportGroups = null;

		if($noImportGroups === null)
		{
			$noImportGroups = array();
			$dbGroups = CLdapServer::GetGroupBan($ldap_server_id);

			while($arGroup = $dbGroups->Fetch())
				$noImportGroups[md5($arGroup['LDAP_GROUP_ID'])] = $arGroup['LDAP_GROUP_ID'];
		}

		if(empty($noImportGroups))
			return false;

		$allUserGroups = $arUserFields['LDAP_GROUPS'];
		$result = false;

		foreach($allUserGroups as $groupId)
		{
			$groupId = trim($groupId);

			if(!empty($groupId) && array_key_exists(md5($groupId), $noImportGroups))
			{
				$result = true;
				break;
			}
		}

		return $result;
	}

	// endregion

	// region synchronization logic

	public static function Sync($ldap_server_id, bool $forceUpdate = false)
	{
		$logger = \Bitrix\Ldap\DI\Container::getInstance()->getSyncLogger();
		$logger->start();
		$logger->collectDebugInfo();

		global $USER, $APPLICATION;
		$bUSERGen = false;
		self::$syncErrors = array();

		if(!is_object($USER))
		{
			$USER = new CUser();
			$bUSERGen = true;
		}

		$dbLdapServers = CLdapServer::GetByID($ldap_server_id);
		if(!($oLdapServer = $dbLdapServers->GetNextServer()))
			return false;

		if(!$oLdapServer->Connect())
			return false;

		if(!$oLdapServer->BindAdmin())
		{
			$oLdapServer->Disconnect();
			return false;
		}

		$APPLICATION->ResetException();
		$db_events = GetModuleEvents("ldap", "OnLdapBeforeSync");

		while($arEvent = $db_events->Fetch())
		{
			$arParams['oLdapServer'] = $oLdapServer;

			if(ExecuteModuleEventEx($arEvent, array(&$arParams))===false)
			{
				if(!($err = $APPLICATION->GetException()))
					$APPLICATION->ThrowException("Unknown error");

				return false;
			}
		}

		$logger->log('OnLdapBeforeSync completed');

		// select all users from LDAP
		$arLdapUsers = array();
		$ldapLoginAttr = mb_strtolower($oLdapServer->arFields["~USER_ID_ATTR"]);

		$APPLICATION->ResetException();
		$dbLdapUsers = $oLdapServer->GetUserList();
		$ldpEx = $APPLICATION->GetException();

		while($arLdapUser = $dbLdapUsers->Fetch())
			$arLdapUsers[mb_strtolower($arLdapUser[$ldapLoginAttr])] = $arLdapUser;

		unset($dbLdapUsers);

		$logger->log(sprintf('%s users fetched from AD', count($arLdapUsers)));

		// select all Bitrix CMS users for this LDAP
		$arUsers = User\Provider::getByServerId((int)$ldap_server_id);

		$logger->log(sprintf('%s users fetched from portal database', count($arUsers)));

		$arDelLdapUsers = array();

		if(!$ldpEx || $ldpEx->msg != 'LDAP_SEARCH_ERROR')
			$arDelLdapUsers = array_diff(array_keys($arUsers), array_keys($arLdapUsers));

		if($oLdapServer->arFields["SYNC_LAST"] <> '')
			$syncTime = MakeTimeStamp($oLdapServer->arFields["SYNC_LAST"]);
		else
			$syncTime = 0;

		$cnt = 0;
		$departmentCache = array();
		// have to update $oLdapServer->arFields["FIELD_MAP"] for user fields
		// for each one of them looking for similar in user list
		foreach($arLdapUsers as $userLogin => $arLdapUserFields)
		{
			if(!is_array($arUsers[$userLogin]))
			{
				//For manual users import - always add
				if($oLdapServer->arFields["SYNC_USER_ADD"] != "Y")
					continue;

				// if user is not found among already existing ones, then import him
				// $arLdapUserFields - user fields from ldap
				$userActive = $oLdapServer->getLdapValueByBitrixFieldName("ACTIVE", $arLdapUserFields);

				if($userActive != "Y")
					continue;

				$arUserFields = $oLdapServer->GetUserFields($arLdapUserFields, $departmentCache);

				if(self::isUserInBannedGroups($ldap_server_id, $arUserFields))
					continue;

				if($oLdapServer->SetUser($arUserFields))
				{
					$cnt++;
				}
				else if(\Bitrix\Ldap\Limit::isUserLimitExceeded())
				{
					self::$syncErrors[] = \Bitrix\Ldap\Limit::getUserLimitNotifyMessage();
					break;
				}
			}
			else
			{
				// if date of update is set, then compare it
				$ldapTime = time();

				if($syncTime > 0
					&& $oLdapServer->arFields["SYNC_ATTR"] <> ''
					&& preg_match("'([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})\.0Z'", $arLdapUserFields[mb_strtolower($oLdapServer->arFields["SYNC_ATTR"])], $arTimeMatch)
					)
				{
					$ldapTime = gmmktime($arTimeMatch[4], $arTimeMatch[5], $arTimeMatch[6], $arTimeMatch[2], $arTimeMatch[3], $arTimeMatch[1]);
					$userTime = MakeTimeStamp($arUsers[$userLogin]["TIMESTAMP_X"]);
				}

				if($syncTime < $ldapTime || $syncTime < $userTime || $forceUpdate)
				{
					$arUserFields = $oLdapServer->GetUserFields($arLdapUserFields, $departmentCache);

					if(self::isUserInBannedGroups($ldap_server_id, $arUserFields))
						continue;

					$arUserFields["ID"] = $arUsers[$userLogin]["ID"];

					if($oLdapServer->SetUser($arUserFields))
					{
						$cnt++;
					}
					else if(\Bitrix\Ldap\Limit::isUserLimitExceeded())
					{
						self::$syncErrors[] = \Bitrix\Ldap\Limit::getUserLimitNotifyMessage();
						break;
					}
				}
			}

			if($USER->LAST_ERROR != '')
			{
				self::$syncErrors[] = $userLogin.': '.$USER->LAST_ERROR;
				$USER->LAST_ERROR = '';
			}

			if ($cnt % 1000 === 0)
			{
				$logger->log($cnt . ' users processed');
			}
		}

		$logger->log('All users processed');

		foreach ($arDelLdapUsers as $userLogin)
		{
			$USER = new CUser();
			if (isset($arUsers[$userLogin]) && $arUsers[$userLogin]['ACTIVE'] == 'Y')
			{
				$ID = intval($arUsers[$userLogin]["ID"]);
				$USER->Update($ID, array('ACTIVE' => 'N'));
			}
		}

		$logger->log('Users deactivated');

		$oLdapServer->Disconnect();
		self::UpdateLastSyncTime((int)$ldap_server_id);

		if($bUSERGen)
			unset($USER);

		$logger->stop();

		return $cnt;
	}

	public static function __UpdateAgentPeriod($server_id, $time)
	{
		$server_id = intval($server_id);
		$time = intval($time);

		CAgent::RemoveAgent("CLdapServer::SyncAgent(".$server_id.");", "ldap");
		if($time>0)
			CAgent::AddAgent("CLdapServer::SyncAgent(".$server_id.");", "ldap", "N", $time*60*60);
	}

	public static function SyncAgent($id)
	{
		$id = (int)$id;

		$container = \Bitrix\Ldap\DI\Container::getInstance();
		$settings = $container->getSettings();

		if ($settings->isModernSyncEnabled())
		{
			$container->getSyncSessionManager()->tryStart($id);
		}
		else
		{
			CLdapServer::Sync($id);
		}

		return "CLdapServer::SyncAgent(".$id.");";
	}

	public static function UpdateLastSyncTime(int $serverId): void
	{
		global $DB;
		$strUpdate = $DB->PrepareUpdate('b_ldap_server', [
			'~SYNC_LAST' => $DB->CurrentTimeFunction(),
		]);

		$strSql = "UPDATE b_ldap_server SET " . $strUpdate . " WHERE ID=" . $serverId;
		$DB->Query($strSql);
	}

	// endregion
}

class __CLDAPServerDBResult extends CDBResult
{
	function Fetch()
	{
		if($res = parent::Fetch())
		{
			$res["ADMIN_PASSWORD"] = Encryption::decrypt($res["ADMIN_PASSWORD"]);
			$res["FIELD_MAP"] = unserialize($res["FIELD_MAP"], ['allowed_classes' => false]);
			if(!is_array($res["FIELD_MAP"]))
				$res["FIELD_MAP"] = Array();
		}

		return $res;
	}

	/**
	 * @return CLDAP|false
	 */
	function GetNextServer()
	{
		if(!($r = $this->GetNext()))
			return $r;
		$ldap = new CLDAP();
		$ldap->arFields = $r;
		return $ldap;
	}
}
