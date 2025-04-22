<?php

use Bitrix\Main\Application;

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/sale/general/user.php");

class CSaleUserAccount extends CAllSaleUserAccount
{
	//********** SELECT **************//
	public static function GetByID($ID)
	{
		global $DB;

		$ID = (int)$ID;
		if ($ID <= 0)
			return false;

		if (isset($GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$ID]) && is_array($GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$ID]) && is_set($GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$ID], "ID"))
		{
			return $GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$ID];
		}
		else
		{
			$strSql =
				"SELECT UA.ID, UA.USER_ID, UA.CURRENT_BUDGET, UA.CURRENCY, UA.NOTES, UA.LOCKED, ".
				"	".$DB->DateToCharFunction("UA.TIMESTAMP_X", "FULL")." as TIMESTAMP_X, ".
				"	".$DB->DateToCharFunction("UA.DATE_LOCKED", "FULL")." as DATE_LOCKED ".
				"FROM b_sale_user_account UA ".
				"WHERE UA.ID = ".$ID." ";

			$dbUserAccount = $DB->Query($strSql);
			if ($arUserAccount = $dbUserAccount->Fetch())
			{
				$GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$ID] = $arUserAccount;
				return $arUserAccount;
			}
		}

		return false;
	}

	public static function GetByUserID($userID, $currency)
	{
		global $DB;

		$userID = (int)$userID;
		if ($userID <= 0)
			return false;

		$currency = trim($currency);
		$currency = preg_replace("#[\W]+#", "", $currency);
		if ($currency == '')
			return false;

		if (isset($GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$userID."_".$currency]) && is_array($GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$userID."_".$currency]) && is_set($GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$userID."_".$currency], "ID"))
		{
			return $GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$userID."_".$currency];
		}
		else
		{
			$strSql =
				"SELECT UA.ID, UA.USER_ID, UA.CURRENT_BUDGET, UA.CURRENCY, UA.NOTES, UA.LOCKED, ".
				"	".$DB->DateToCharFunction("UA.TIMESTAMP_X", "FULL")." as TIMESTAMP_X, ".
				"	".$DB->DateToCharFunction("UA.DATE_LOCKED", "FULL")." as DATE_LOCKED ".
				"FROM b_sale_user_account UA ".
				"WHERE UA.USER_ID = ".$userID." ".
				"	AND UA.CURRENCY = '".$DB->ForSql($currency)."' ";

			$dbUserAccount = $DB->Query($strSql);
			if ($arUserAccount = $dbUserAccount->Fetch())
			{
				$GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$userID."_".$currency] = $arUserAccount;
				return $arUserAccount;
			}
		}

		return false;
	}

		
	/**
	 * <p>Возвращает список пользователей в виде объекта класса <a class="link" href="https://dev.1c-bitrix.ru/api_help/main/reference/cdbresult/index.php">CDBResult</a>. Статический метод.</p><h4>Смотрите также</h4><ul> <li> <a class="link" href="http://dev.1c-bitrix.ru/api_help/main/reference/cuser/index.php">Поля CUser</a> </li> <li> <a class="link" href="http://dev.1c-bitrix.ru/api_help/main/reference/cuser/getbyid.php">CUser::GetByID</a> </li> <li> <a class="link" href="http://dev.1c-bitrix.ru/api_help/main/reference/cuser/getbylogin.php">CUser::GetByLogin</a> </li> </ul>
	 *
	 *
	 * @param string $&by = "timestamp_x" ссылка на переменную с полем для сортировки, может принимать значения: <ul> <li> <b>id</b> - ID пользователя </li> <li> <b>active</b> - активность </li> <li> <b>last_login</b> - дата последней авторизации </li> <li> <b>login</b> - <span class="learning-lesson-detail-block js-detail-info-block"> <span class="learning-lesson-detail-word js-detail-info-word">имя входа</span> <span class="learning-lesson-detail-body js-detail-info-body"> <span class="learning-lesson-detail-body-inner js-detail-info-body-inner"> <button class="learning-lesson-detail-close-button js-detail-close-button" type="button"></button> Если у вас задача найти пользователя именно по логину, то используйте <a class="link" href="http://dev.1c-bitrix.ru/api_help/main/reference/cuser/getbylogin.php">CUser::GetByLogin</a>. Этот метод ищет точное совпадение с логином. </span> </span> </span> </li> <li> <b>email</b> - E-Mail адрес </li> <li> <b>name</b> - имя </li> <li> <b>ntopcount</b> - параметр постраничной навигации, ограничивающий количество возвращаемых элементов </li> <li> <b>last_name</b> - фамилия </li> <li> <b>timestamp_x</b> - дата изменения </li> <li> <b>date_register</b> - дата регистрации </li> <li> <b>personal_profession</b> - профессия </li> <li> <b>personal_www</b> - WWW-страница </li> <li> <b>personal_icq</b> - номер ICQ </li> <li> <b>personal_gender</b> - пол ("M" - мужской; "F" - женский) </li> <li> <b>personal_birthday</b> - день рождения </li> <li> <b>personal_photo</b> - ID файла-фотографии </li> <li> <b>personal_phone</b> - номер телефона </li> <li> <b>personal_fax</b> - номер факса </li> <li> <b>personal_mobile</b> - номер мобильного </li> <li> <b>personal_pager</b> - номер пейджера </li> <li> <b>personal_street</b> - улица </li> <li> <b>personal_mailbox</b> - почтовый ящик </li> <li> <b>personal_city</b> - город </li> <li> <b>personal_state</b> - область / край </li> <li> <b>personal_zip</b> - почтовый индекс </li> <li> <b>personal_country</b> - код страны </li> <li> <b>personal_notes</b> - дополнительные заметки </li> <li> <b>work_company</b> - наименования компании </li> <li> <b>work_department</b> - отдел </li> <li> <b>work_position</b> - должность </li> <li> <b>work_www</b> - WWW-страница компании </li> <li> <b>work_phone</b> - рабочий телефон </li> <li> <b>work_fax</b> - рабочий факс </li> <li> <b>work_pager</b> - рабочий пейджер </li> <li> <b>work_street</b> - улица компании </li> <li> <b>work_mailbox</b> - почтовый ящик компании </li> <li> <b>work_city</b> - город компании </li> <li> <b>work_state</b> - область / край компании </li> <li> <b>work_zip</b> - почтовый индекс компании </li> <li> <b>work_country</b> - код страны компании </li> <li> <b>work_profile</b> - направление деятельности компании </li> <li> <b>work_notes</b> - дополнительные заметки касаемо места работы </li> <li> <b>admin_notes</b> - комментарий администратора </li> </ul> <p>Начиная с версии ядра 11.0.13 в параметре можно передавать массив вида array("field1"=&gt;"asc", "field2"=&gt;"desc") для множественной сортировки. Значения ключей массива совпадают с перечисленными выше.</p><br /><br /><hr /><br /><br />
	 * @param string $&order = "desc" Ссылка на переменную с порядком сортировки, может принимать значения: <br> <ul> <li> <b>asc</b> - по возрастанию </li> <li> <b>desc</b> - по убыванию </li> </ul> <p>При использовании массива в параметре <em>by</em> данный параметр игнорируется. Значения c <i>nulls</i> не работают, например: <i>desc,nulls</i>.</p><br /><br /><hr /><br /><br />
	 * @param array $arFilter = array() Массив для фильтрации пользователей. (<a class="link" href="http://dev.1c-bitrix.ruhttp://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=43&amp;LESSON_ID=2683#types" target="_blank">Типы фильтрации</a>) В массиве допустимы следующие индексы: <ul> <li> <b>ID</b> - по ID пользователя </li> <li> <b>XML_ID</b> - по XML_ID пользователя </li> <li> <b>TIMESTAMP_1</b> - дата изменения профайла пользователя "с" </li> <li> <b>TIMESTAMP_2</b> - дата изменения профайла пользователя "по" </li> <li> <b>LAST_LOGIN_1</b> - дата последнего логина пользователя "с" </li> <li> <b>LAST_LOGIN_2</b> - дата последнего логина пользователя "по" </li> <li> <b>LAST_ACTIVITY</b> - интервал в секундах </li> <li> <b>ACTIVE</b> - фильтр по активности (Y|N) </li> <li> <b>LOGIN_EQUAL</b> - по имени входа (ищет прямое совпадение с логином) </li> <li> <b>LOGIN</b> - по имени входа (ищет подстроку в логине) </li> <li> <b>NAME</b> - по имени и фамилии </li> <li> <b>EMAIL</b> - по E-Mail адресу </li> <li> <b>COUNTRY_ID</b> - по коду страны, оставлен для обратной совместимости. Сейчас при его использовании производится фильтрация по WORK_COUNTRY.</li> <li> <b>GROUPS_ID</b> - по группам (массив с кодами групп пользователей) </li> <li> <b>PERSONAL_BIRTHDAY_1</b> - день рождения "с" </li> <li> <b>PERSONAL_BIRTHDAY_2</b> - день рождения "по" </li> <li> <b>KEYWORDS</b> - по нижеследующим полям профайла помеченных символом - * </li> <li> * <b>PERSONAL_PROFESSION</b> - профессия </li> <li> * <b>PERSONAL_WWW</b> - WWW-страница </li> <li> * <b>PERSONAL_ICQ</b> - номер ICQ </li> <li> * <b>PERSONAL_GENDER</b> - пол ("M" - мужской; "F" - женский) </li> <li> * <b>PERSONAL_PHOTO</b> - ID файла - фотографии (таблица b_file) </li> <li> * <b>PERSONAL_PHONE</b> - номер телефона </li> <li> * <b>PERSONAL_FAX</b> - номер факса </li> <li> * <b>PERSONAL_MOBILE</b> - номер мобильного </li> <li> * <b>PERSONAL_PAGER</b> - номер пейджера </li> <li> * <b>PERSONAL_STREET</b> - улица </li> <li> * <b>PERSONAL_MAILBOX</b> - почтовый ящик </li> <li> * <b>PERSONAL_CITY</b> - город </li> <li> * <b>PERSONAL_STATE</b> - область / край </li> <li> * <b>PERSONAL_ZIP</b> - почтовый индекс </li> <li> * <b>PERSONAL_COUNTRY</b> - код страны (хранится в файлах \bitrix\modules\main\lang\ru\tools.php, \bitrix\modules\main\lang\en\tools.php) </li> <li> * <b>PERSONAL_NOTES</b> - дополнительные заметки </li> <li> * <b>WORK_COMPANY</b> - наименования компании </li> <li> * <b>WORK_DEPARTMENT</b> - отдел </li> <li> * <b>WORK_POSITION</b> - должность </li> <li> * <b>WORK_WWW</b> - WWW-страница компании </li> <li> * <b>WORK_PHONE</b> - рабочий телефон </li> <li> * <b>WORK_FAX</b> - рабочий факс </li> <li> * <b>WORK_PAGER</b> - рабочий пейджер </li> <li> * <b>WORK_STREET</b> - улица компании </li> <li> * <b>WORK_MAILBOX</b> - почтовый ящик компании </li> <li> * <b>WORK_CITY</b> - город компании </li> <li> * <b>WORK_STATE</b> - область / край компании </li> <li> * <b>WORK_ZIP</b> - почтовый индекс компании </li> <li> * <b>WORK_COUNTRY</b> - код страны компании (хранится в файлах \bitrix\modules\main\lang\ru\tools.php, \bitrix\modules\main\lang\en\tools.php) </li> <li> * <b>WORK_PROFILE</b> - направление деятельности компании </li> <li> * <b>WORK_NOTES</b> - дополнительные заметки касаемо места работы </li> <li> * <b>ADMIN_NOTES</b> - комментарий администратора (доступен для просмотра и редактирования только администратору сайта) </li> </ul>  - в данных полях допускается <a class="link" href="http://dev.1c-bitrix.ru/api_help/main/general/filter.php">сложные условия</a>. Сложные условия для данного поля работают только при указании: <b>ID</b>. При указании <b>!ID</b> и <b>&gt;ID</b>, сложные условия работать не будут. <br> * - поиск по "KEYWORDS" по сути является поиском по полям отмеченных символом "*" <p class="note">В фильтре можно использовать и пользовательские поля, типа <code>UF_*</code>.</p><br /><br /><hr /><br /><br />
	 * @param array{'SELECT':array, 'NAV_PARAMS':array, 'FIELDS': array} $arParams = array() Массив с дополнительными параметрами метода. Может содержать ключи: <br> <p><strong>SELECT</strong> - массив с идентификаторами пользовательских полей для их выборки в результат, например array("UF_TEXT_1", "UF_STRUCTURE"). Для указания выборки всех полей используйте маску: array("UF_*").</p> <p><strong>NAV_PARAMS</strong> - массив с параметрами навигации, может использоваться для ограничения размера выборки. Например: array("nPageSize"=&gt;"20"). При указании NAV_PARAMS строится ограниченный по размеру список результатов, учитывающий номер страницы в постраничной навигации (для mysql выборка производится с указанием limit). С версии ядра 11.0.14 в массиве можно указать параметр "nTopCount" для ограничения выборки по количеству записей.</p> <p><strong>FIELDS</strong> (с версии ядра 11.0.13) - массив с идентификаторами полей для выборки. Если не указан или пустой, то выбираются все поля. Возможные значения:</p> <table height="0" cellspacing="0" cellpadding="0" bgcolor="" width="100%"> <tbody> <tr> <td>ID</td> <td>PERSONAL_WWW</td> <td>PERSONAL_ZIP</td> <td>IS_ONLINE</td> </tr> <tr> <td>ACTIVE</td> <td>PERSONAL_ICQ</td> <td>PERSONAL_COUNTRY</td> <td>WORK_CITY</td> </tr> <tr> <td>LAST_LOGIN</td> <td>PERSONAL_GENDER</td> <td>PERSONAL_NOTES</td> <td>WORK_STATE</td> </tr> <tr> <td>LOGIN</td> <td>PERSONAL_PHOTO</td> <td>WORK_COMPANY</td> <td>WORK_ZIP</td> </tr> <tr> <td>EMAIL</td> <td>PERSONAL_PHONE</td> <td>WORK_DEPARTMENT</td> <td>WORK_COUNTRY</td> </tr> <tr> <td>NAME</td> <td>PERSONAL_FAX</td> <td>WORK_POSITION</td> <td>WORK_PROFILE</td> </tr> <tr> <td>LAST_NAME</td> <td>PERSONAL_MOBILE</td> <td>WORK_WWW</td> <td>WORK_NOTES</td> </tr> <tr> <td>SECOND_NAME</td> <td>PERSONAL_PAGER</td> <td>WORK_PHONE</td> <td>ADMIN_NOTES</td> </tr> <tr> <td>TIMESTAMP_X</td> <td>PERSONAL_STREET</td> <td>WORK_FAX</td> <td>XML_ID</td> </tr> <tr> <td>PERSONAL_BIRTHDAY</td> <td>PERSONAL_MAILBOX</td> <td>WORK_PAGER</td> <td>PASSWORD</td> </tr> <tr> <td>DATE_REGISTER</td> <td>PERSONAL_CITY</td> <td>WORK_STREET</td> <td>LOGIN_ATTEMPTS</td> </tr> <tr> <td>PERSONAL_PROFESSION</td> <td>PERSONAL_STATE</td> <td>WORK_MAILBOX</td> <td>STORED_HASH</td> </tr> <tr> <td>CHECKWORD_TIME</td> <td>EXTERNAL_AUTH_ID</td> <td>CONFIRM_CODE</td> <td>TITLE</td> </tr> <tr> <td>LAST_ACTIVITY_DATE</td> <td>AUTO_TIME_ZONE</td> <td>TIME_ZONE</td> <td> LID </td> </tr> <tr> <td>CHECKWORD</td> <td></td> <td></td> <td></td> </tr> </tbody> </table> Не рекомендуется устанавливать <code>'LID' => SITE_ID</code>. В этом случае в запросе будет <code>(upper(U.LID) like upper('%s1%')</code>.<br><br>Лучше использовать новое ядро: <code>\Bitrix\Main\UserTable::getList(array("filter"=>array("=LID" => 's1'))); </code>.
	 * @return \CDBResult
	 *
	 * @link https://dev.1c-bitrix.ru/api_help/main/reference/cuser/getlist.php
	 * @author phpDoc author - generator by hipot at 22.04.2025
	 */
	public static function GetList($arOrder = array(), $arFilter = array(), $arGroupBy = false, $arNavStartParams = false, $arSelectFields = array())
	{
		global $DB;

		if (empty($arSelectFields))
			$arSelectFields = array("ID", "USER_ID", "CURRENT_BUDGET", "CURRENCY", "LOCKED", "NOTES", "TIMESTAMP_X", "DATE_LOCKED");

		// FIELDS -->
		$arFields = array(
				"ID" => array("FIELD" => "UA.ID", "TYPE" => "int"),
				"USER_ID" => array("FIELD" => "UA.USER_ID", "TYPE" => "int"),
				"CURRENT_BUDGET" => array("FIELD" => "UA.CURRENT_BUDGET", "TYPE" => "double"),
				"CURRENCY" => array("FIELD" => "UA.CURRENCY", "TYPE" => "string"),
				"LOCKED" => array("FIELD" => "UA.LOCKED", "TYPE" => "char"),
				"NOTES" => array("FIELD" => "UA.NOTES", "TYPE" => "string"),
				"TIMESTAMP_X" => array("FIELD" => "UA.TIMESTAMP_X", "TYPE" => "datetime"),
				"DATE_LOCKED" => array("FIELD" => "UA.DATE_LOCKED", "TYPE" => "datetime"),
				"USER_LOGIN" => array("FIELD" => "U.LOGIN", "TYPE" => "string", "FROM" => "INNER JOIN b_user U ON (UA.USER_ID = U.ID)"),
				"USER_ACTIVE" => array("FIELD" => "U.ACTIVE", "TYPE" => "char", "FROM" => "INNER JOIN b_user U ON (UA.USER_ID = U.ID)"),
				"USER_NAME" => array("FIELD" => "U.NAME", "TYPE" => "string", "FROM" => "INNER JOIN b_user U ON (UA.USER_ID = U.ID)"),
				"USER_LAST_NAME" => array("FIELD" => "U.LAST_NAME", "TYPE" => "string", "FROM" => "INNER JOIN b_user U ON (UA.USER_ID = U.ID)"),
				"USER_EMAIL" => array("FIELD" => "U.EMAIL", "TYPE" => "string", "FROM" => "INNER JOIN b_user U ON (UA.USER_ID = U.ID)"),
				"USER_USER" => array("FIELD" => "U.LOGIN,U.NAME,U.LAST_NAME,U.EMAIL,U.ID", "WHERE_ONLY" => "Y", "TYPE" => "string", "FROM" => "INNER JOIN b_user U ON (UA.USER_ID = U.ID)")
			);
		// <-- FIELDS

		$arSqls = CSaleOrder::PrepareSql($arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields);

		$arSqls["SELECT"] = str_replace("%%_DISTINCT_%%", "", $arSqls["SELECT"]);

		if (empty($arGroupBy) && is_array($arGroupBy))
		{
			$strSql =
				"SELECT ".$arSqls["SELECT"]." ".
				"FROM b_sale_user_account UA ".
				"	".$arSqls["FROM"]." ";
			if ($arSqls["WHERE"] <> '')
				$strSql .= "WHERE ".$arSqls["WHERE"]." ";
			if ($arSqls["GROUPBY"] <> '')
				$strSql .= "GROUP BY ".$arSqls["GROUPBY"]." ";

			//echo "!1!=".htmlspecialcharsbx($strSql)."<br>";

			$dbRes = $DB->Query($strSql);
			if ($arRes = $dbRes->Fetch())
				return $arRes["CNT"];
			else
				return false;
		}

		$strSql =
			"SELECT ".$arSqls["SELECT"]." ".
			"FROM b_sale_user_account UA ".
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
				"FROM b_sale_user_account UA ".
				"	".$arSqls["FROM"]." ";
			if ($arSqls["WHERE"] <> '')
				$strSql_tmp .= "WHERE ".$arSqls["WHERE"]." ";
			if ($arSqls["GROUPBY"] <> '')
				$strSql_tmp .= "GROUP BY ".$arSqls["GROUPBY"]." ";

			//echo "!2.1!=".htmlspecialcharsbx($strSql_tmp)."<br>";

			$dbRes = $DB->Query($strSql_tmp);
			$cnt = 0;
			if ($arSqls["GROUPBY"] == '')
			{
				if ($arRes = $dbRes->Fetch())
					$cnt = $arRes["CNT"];
			}
			else
			{
				// FOR MYSQL!!! ANOTHER CODE FOR ORACLE
				$cnt = $dbRes->SelectedRowsCount();
			}

			$dbRes = new CDBResult();

			//echo "!2.2!=".htmlspecialcharsbx($strSql)."<br>";

			$dbRes->NavQuery($strSql, $cnt, $arNavStartParams);
		}
		else
		{
			if (is_array($arNavStartParams) && intval($arNavStartParams["nTopCount"])>0)
				$strSql .= "LIMIT ".intval($arNavStartParams["nTopCount"]);

			//echo "!3!=".htmlspecialcharsbx($strSql)."<br>";

			$dbRes = $DB->Query($strSql);
		}

		return $dbRes;
	}

	public static function Add($arFields)
	{
		global $DB;

		$arFields1 = [];
		foreach ($arFields as $key => $value)
		{
			if (mb_substr($key, 0, 1) == "=")
			{
				$arFields1[mb_substr($key, 1)] = $value;
				unset($arFields[$key]);
			}
		}

		if (!CSaleUserAccount::CheckFields("ADD", $arFields, 0))
		{
			return false;
		}

		foreach (GetModuleEvents('sale', 'OnBeforeUserAccountAdd', true) as $arEvent)
		{
			if (ExecuteModuleEventEx($arEvent, array(&$arFields))===false)
			{
				return false;
			}
		}

		if (!isset($arFields1['TIMESTAMP_X']))
		{
			$connection = Application::getConnection();
			$helper = $connection->getSqlHelper();
			unset($arFields['TIMESTAMP_X']);
			$arFields['~TIMESTAMP_X'] = $helper->getCurrentDateTimeFunction();
			unset($helper, $connection);
		}

		$arInsert = $DB->PrepareInsert("b_sale_user_account", $arFields);

		foreach ($arFields1 as $key => $value)
		{
			if ($arInsert[0] <> '') $arInsert[0] .= ", ";
			$arInsert[0] .= $key;
			if ($arInsert[1] <> '') $arInsert[1] .= ", ";
			$arInsert[1] .= $value;
		}

		$strSql = "INSERT INTO b_sale_user_account(".$arInsert[0].") VALUES(".$arInsert[1].")";
		$DB->Query($strSql);

		$ID = (int)$DB->LastID();

		foreach (GetModuleEvents('sale', 'OnAfterUserAccountAdd', true) as $arEvent)
		{
			ExecuteModuleEventEx($arEvent, Array($ID, $arFields));
		}

		return $ID;
	}

	public static function Update($ID, $arFields)
	{
		global $DB;

		$ID = (int)$ID;
		if ($ID <= 0)
			return false;

		$arFields1 = array();
		foreach ($arFields as $key => $value)
		{
			if (mb_substr($key, 0, 1) == "=")
			{
				$arFields1[mb_substr($key, 1)] = $value;
				unset($arFields[$key]);
			}
		}

		if (!CSaleUserAccount::CheckFields("UPDATE", $arFields, $ID))
		{
			return false;
		}

		foreach (GetModuleEvents('sale', 'OnBeforeUserAccountUpdate', true) as $arEvent)
		{
			if (ExecuteModuleEventEx($arEvent, array($ID, &$arFields))===false)
			{
				return false;
			}
		}

		$arOldUserAccount = CSaleUserAccount::GetByID($ID);

		if (!isset($arFields1['TIMESTAMP_X']))
		{
			$connection = Application::getConnection();
			$helper = $connection->getSqlHelper();
			unset($arFields['TIMESTAMP_X']);
			$arFields['~TIMESTAMP_X'] = $helper->getCurrentDateTimeFunction();
			unset($helper, $connection);
		}

		$strUpdate = $DB->PrepareUpdate("b_sale_user_account", $arFields);

		foreach ($arFields1 as $key => $value)
		{
			if ($strUpdate <> '') $strUpdate .= ", ";
			$strUpdate .= $key."=".$value." ";
		}

		$strSql = "UPDATE b_sale_user_account SET ".$strUpdate." WHERE ID = ".$ID." ";
		$DB->Query($strSql);

		unset($GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$ID]);
		unset($GLOBALS["SALE_USER_ACCOUNT"]["SALE_USER_ACCOUNT_CACHE_".$arOldUserAccount["USER_ID"]."_".$arOldUserAccount["CURRENCY"]]);

		foreach (GetModuleEvents('sale', 'OnAfterUserAccountUpdate', true) as $arEvent)
		{
			ExecuteModuleEventEx($arEvent, array($ID, $arFields));
		}

		return $ID;
	}
}
