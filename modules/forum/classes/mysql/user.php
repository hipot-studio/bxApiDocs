<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/forum/classes/general/user.php");

class CForumUser extends CAllForumUser
{
		
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
	public static function GetList($arOrder = Array("ID"=>"ASC"), $arFilter = Array(), $arAddParams = array())
	{
		global $DB;
		$sqlHelper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$arSqlSearch = array();
		$arSqlOrder = array();
		$strSqlSearch = "";
		$strSqlOrder = "";
		$arFilter = (is_array($arFilter) ? $arFilter : array());
		$arAddParams = is_array($arAddParams) ? $arAddParams : [];
		if (isset($arAddParams["nameTemplate"]))
		{
			$arAddParams["sNameTemplate"] = $arAddParams["nameTemplate"];
			unset($arAddParams["nameTemplate"]);
		}

		if (isset($arFilter['PERSONAL_BIRTHDAY_DATE']))
		{
			$subQuery = "SELECT U.ID FROM b_user U WHERE ";
			$key_res = CForumNew::GetFilterOperation($arFilter['PERSONAL_BIRTHDAY_DATE']);
			$key = mb_strtoupper($key_res["FIELD"]);
			$val = $arFilter['PERSONAL_BIRTHDAY_DATE'];
			$strNegative = $key_res["NEGATIVE"];
			$strOperation = $key_res["OPERATION"];
			$subQuery .= ( $strNegative === "Y"
					? " U.PERSONAL_BIRTHDAY IS NULL OR NOT "
					: " U.PERSONAL_BIRTHDAY IS NOT NULL AND ")
						. "(" . $sqlHelper->formatDate('MM-DD', 'U.PERSONAL_BIRTHDAY')
						. $strOperation . " '".$DB->ForSql($val)."')";
			$db_sub_res = $DB->Query($subQuery);
			$arUserID = array();
			if ($db_sub_res)
			{
				while($ar_sub_res = $db_sub_res->Fetch())
					$arUserID[] = $ar_sub_res['ID'];
			}
			if (sizeof($arUserID) > 0)
			{
				if (sizeof($arUserID) > 50)
					$arUserID = array_slice($arUserID, 0, 50);

				unset($arFilter['PERSONAL_BIRTHDAY_DATE']);
				$arFilter['@USER_ID'] = $arUserID;
			}
		}

		foreach ($arFilter as $key => $val)
		{
			$key_res = CForumNew::GetFilterOperation($key);
			$key = mb_strtoupper($key_res["FIELD"]);
			$strNegative = $key_res["NEGATIVE"];
			$strOperation = $key_res["OPERATION"];

			switch ($key)
			{
				case "USER_ID":
					$userID = intval($val);
					if (is_array($val) && $strOperation == 'IN')
					{
						$userID = array();
						foreach($val as $valI)
							$userID[] = intval($valI);
						$userID = array_unique($userID);
						if (empty($userID))
							$val = $userID = 0;
						else
							$userID = '(' . implode(', ', $userID). ')';
					}
					if (!is_array($val) && intval($userID)<=0)
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(U.ID IS NULL OR U.ID<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" U.ID IS NULL OR NOT ":"")."(U.ID ".$strOperation." ".$userID." )";
					break;
				case "ID":
				case "RANK_ID":
				case "NUM_POSTS":
				case "AVATAR":
					if (intval($val)<=0)
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FU.".$key." IS NULL OR FU.".$key."<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FU.".$key." IS NULL OR NOT ":"")."(FU.".$key." ".$strOperation." ".intval($val)." )";
					break;
				case "SHOW_NAME":
				case "HIDE_FROM_ONLINE":
				case "SUBSC_GROUP_MESSAGE":
				case "SUBSC_GET_MY_MESSAGE":
				case "ALLOW_POST":
					if ($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FU.".$key." IS NULL OR LENGTH(FU.".$key.")<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FU.".$key." IS NULL OR NOT ":"")."(FU.".$key." ".$strOperation." '".$DB->ForSql($val)."' )";
					break;
				case "ACTIVE":
					if ($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(U.".$key." IS NULL OR LEN(U.".$key.")<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" U.".$key." IS NULL OR NOT ":"")."(U.".$key." ".$strOperation." '".$DB->ForSql($val)."' )";
					break;
				case "PERSONAL_BIRTHDATE":
					if ($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(U.PERSONAL_BIRTHDATE IS NULL)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" U.PERSONAL_BIRTHDATE IS NULL OR NOT ":"")."(U.PERSONAL_BIRTHDATE ".$strOperation." '".$DB->ForSql($val)."')";
					break;
				case "PERSONAL_BIRTHDAY":
					if($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(U.PERSONAL_BIRTHDAY IS NULL)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" U.PERSONAL_BIRTHDAY IS NULL OR NOT ":"")."(U.PERSONAL_BIRTHDAY ".$strOperation." ".$DB->CharToDateFunction($DB->ForSql($val), "SHORT").")";
					break;
				case "PERSONAL_BIRTHDAY_DATE":
					$arSqlSearch[] = ($strNegative=="Y"?" U.PERSONAL_BIRTHDAY IS NULL OR NOT ":"")."(" . $sqlHelper->formatDate('MM-DD', 'U.PERSONAL_BIRTHDAY') . $strOperation." '".$DB->ForSql($val)."')";
					break;
				case "LAST_VISIT":
					if($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FU.LAST_VISIT IS NULL)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FU.LAST_VISIT IS NULL OR NOT ":"")."(FU.LAST_VISIT ".$strOperation." ".$DB->CharToDateFunction($DB->ForSql($val), "FULL").")";
					break;
				case "SHOW_ABC":
					$val = trim($val);
					if (!empty($val) && $val != "Y")
					{
						$arSqlSearch[] =
							"(
								(
									FU.SHOW_NAME = 'Y'
									AND
									LENGTH(TRIM(CONCAT_WS('',".self::GetNameFieldsForQuery($arAddParams["sNameTemplate"])."))) > 0
									AND
									(REPLACE(CONCAT_WS(' ',".self::GetNameFieldsForQuery($arAddParams["sNameTemplate"])."), '  ', ' ') LIKE '%".$DB->ForSql($val)."%')
								)
								OR
								(
									(
										FU.SHOW_NAME != 'Y'
										OR
										FU.SHOW_NAME IS NULL
										OR
										(
											FU.SHOW_NAME = 'Y'
											AND
											LENGTH(TRIM(CONCAT_WS('',".self::GetNameFieldsForQuery($arAddParams["sNameTemplate"])."))) <= 0
										)
									)
									AND
									(
										U.LOGIN LIKE '%".$DB->ForSql($val)."%'
									)
								)
							)";
					}
					break;
			}
		}
		if (count($arSqlSearch) > 0)
			$strSqlSearch = " AND (".implode(") AND (", $arSqlSearch).") ";

		foreach ($arOrder as $by=>$order)
		{
			$by = mb_strtoupper($by);
			$order = mb_strtoupper($order);

			if ($order!="ASC") $order = "DESC";

			if ($by == "USER_ID") $arSqlOrder[] = " U.ID ".$order." ";
			elseif ($by == "SHOW_NAME") $arSqlOrder[] = " FU.SHOW_NAME ".$order." ";
			elseif ($by == "HIDE_FROM_ONLINE") $arSqlOrder[] = " FU.HIDE_FROM_ONLINE ".$order." ";
			elseif ($by == "SUBSC_GROUP_MESSAGE") $arSqlOrder[] = " FU.SUBSC_GROUP_MESSAGE ".$order." ";
			elseif ($by == "SUBSC_GET_MY_MESSAGE") $arSqlOrder[] = " FU.SUBSC_GET_MY_MESSAGE ".$order." ";
			elseif ($by == "NUM_POSTS") $arSqlOrder[] = " FU.NUM_POSTS ".$order." ";
			elseif ($by == "LAST_POST") $arSqlOrder[] = " FU.LAST_POST ".$order." ";
			elseif ($by == "POINTS") $arSqlOrder[] = " FU.POINTS ".$order." ";
			elseif ($by == "NAME") $arSqlOrder[] = " U.NAME ".$order." ";
			elseif ($by == "LAST_NAME") $arSqlOrder[] = " U.LAST_NAME ".$order." ";
			elseif ($by == "LOGIN") $arSqlOrder[] = " U.LOGIN ".$order." ";
			elseif ($by == "LAST_VISIT") $arSqlOrder[] = " FU.LAST_VISIT ".$order." ";
			elseif ($by == "DATE_REGISTER") $arSqlOrder[] = " U.DATE_REGISTER ".$order." ";
			elseif ($by == "SHOW_ABC") $arSqlOrder[] = " SHOW_ABC ".$order." ";
			else
			{
				$arSqlOrder[] = " FU.ID ".$order." ";
				$by = "ID";
			}
		}
		DelDuplicateSort($arSqlOrder);
		if (count($arSqlOrder) > 0)
			$strSqlOrder = " ORDER BY ".implode(", ", $arSqlOrder);

		$strSql =
			"SELECT FU.ID, U.ID as USER_ID, FU.SHOW_NAME, FU.DESCRIPTION, FU.IP_ADDRESS,
				FU.REAL_IP_ADDRESS, FU.AVATAR, FU.NUM_POSTS, FU.POINTS as NUM_POINTS,
				FU.INTERESTS, FU.SUBSC_GROUP_MESSAGE, FU.SUBSC_GET_MY_MESSAGE,
				FU.LAST_POST, FU.ALLOW_POST, FU.SIGNATURE, FU.RANK_ID,
				U.EMAIL, U.NAME, U.SECOND_NAME, U.LAST_NAME, U.LOGIN, U.PERSONAL_BIRTHDATE,
				".$DB->DateToCharFunction("FU.DATE_REG", "SHORT")." as DATE_REG,
				".$DB->DateToCharFunction("FU.LAST_VISIT", "FULL")." as LAST_VISIT,
				".$DB->DateToCharFunction("FU.LAST_VISIT", "SHORT")." as LAST_VISIT_SHORT,
				".$DB->DateToCharFunction("U.DATE_REGISTER", "SHORT")." as DATE_REGISTER_SHORT,
				U.PERSONAL_ICQ, U.PERSONAL_WWW, U.PERSONAL_PROFESSION, U.DATE_REGISTER,
				U.PERSONAL_CITY, U.PERSONAL_COUNTRY, U.EXTERNAL_AUTH_ID, U.PERSONAL_PHOTO,
				U.PERSONAL_GENDER, FU.POINTS, FU.HIDE_FROM_ONLINE,
				".$DB->DateToCharFunction("U.PERSONAL_BIRTHDAY", "SHORT")." as PERSONAL_BIRTHDAY ".
				(array_key_exists("SHOW_ABC", $arFilter) || array_key_exists("sNameTemplate", $arAddParams) ?
					", \n".self::GetFormattedNameFieldsForSelect(
						array_merge(
							$arAddParams,
							array(
								"sUserTablePrefix" => "U.",
								"sForumUserTablePrefix" => "FU.",
								"sFieldName" => "SHOW_ABC")
						),
						false
					)
					:
					""
				).
				((isset($arFilter['USER_ID']) || isset($arFilter['@USER_ID'])) ?
					" FROM b_user U LEFT JOIN b_forum_user FU ON (FU.USER_ID = U.ID)"
					:
					" FROM b_forum_user FU LEFT JOIN b_user U ON (FU.USER_ID = U.ID)"
				).
				" WHERE 1 = 1 ".$strSqlSearch." \n".
				$strSqlOrder;

		if (!empty($arAddParams["nTopCount"]))
			$strSql .= " LIMIT 0," . intval($arAddParams["nTopCount"]);
		if (isset($arAddParams["bDescPageNumbering"]) && empty($arAddParams["nTopCount"]))
		{
			$iCnt = 0;
			$strSqlCount =
				"SELECT COUNT('x') as CNT ".
				((isset($arFilter['USER_ID']) || isset($arFilter['@USER_ID'])) ?
					" FROM b_user U LEFT JOIN b_forum_user FU ON (FU.USER_ID = U.ID)"
					:
					" FROM b_forum_user FU LEFT JOIN b_user U ON (FU.USER_ID = U.ID)"
				).
				" WHERE 1 = 1 ".$strSqlSearch;
			$db_res = $DB->Query($strSqlCount);
			if ($db_res && ($res = $db_res->Fetch()))
				$iCnt = $res["CNT"];

			$db_res =  new CDBResult();
			$db_res->NavQuery($strSql, $iCnt, $arAddParams);
		}
		else
		{
			$db_res = $DB->Query($strSql);
		}
		return $db_res;
	}

	public static function GetListEx($arOrder = Array("ID"=>"ASC"), $arFilter = Array())
	{
		global $DB;
		$sqlHelper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$arSqlSearch = array();
		$arSqlSelect = array();
		$arSqlFrom = array();
		$arSqlGroup = array();
		$arSqlOrder = array();
		$arSql = array();
		$strSqlSearch = "";
		$strSqlSelect = "";
		$strSqlFrom = "";
		$strSqlGroup = "";
		$strSqlOrder = "";
		$strSql = "";
		$tmp = array();
		$arFilter = (is_array($arFilter) ? $arFilter : array());

		$arMainUserFields = array("LOGIN"=>"S", "NAME"=>"S", "LAST_NAME"=>"S", "SECOND_NAME"=>"S",
			"PERSONAL_PROFESSION"=>"S", "PERSONAL_WWW"=>"S", "PERSONAL_ICQ"=>"S", "PERSONAL_GENDER"=>"E",
			"PERSONAL_PHONE"=>"S", "PERSONAL_FAX"=>"S", "PERSONAL_MOBILE"=>"S", "PERSONAL_PAGER"=>"S",
			"PERSONAL_STREET"=>"S", "PERSONAL_MAILBOX"=>"S", "PERSONAL_CITY"=>"S", "PERSONAL_STATE"=>"S",
			"PERSONAL_ZIP"=>"S", "PERSONAL_COUNTRY"=>"I", "EXTERNAL_AUTH_ID"=>"S", "PERSONAL_NOTES"=>"S", "WORK_COMPANY"=>"S",
			"WORK_DEPARTMENT"=>"S", "WORK_POSITION"=>"S", "WORK_WWW"=>"S", "WORK_PHONE"=>"S", "WORK_FAX"=>"S",
			"WORK_PAGER"=>"S", "WORK_STREET"=>"S", "WORK_MAILBOX"=>"S", "WORK_CITY"=>"S", "WORK_STATE"=>"S",
			"WORK_ZIP"=>"S", "WORK_COUNTRY"=>"I", "WORK_PROFILE"=>"S", "WORK_NOTES"=>"S");
		$arSqlSelectConst = array(
			"FU.ID" => "FU.ID",
			"USER_ID" => "U.ID",
			"FU.SHOW_NAME" => "FU.SHOW_NAME",
			"FU.DESCRIPTION" => "FU.DESCRIPTION",
			"FU.IP_ADDRESS" => "FU.IP_ADDRESS",
			"FU.REAL_IP_ADDRESS" => "FU.REAL_IP_ADDRESS",
			"FU.AVATAR" => "FU.AVATAR",
			"FU.NUM_POSTS" => "FU.NUM_POSTS",
			"NUM_POINTS" => "FU.POINTS",
			"FU.INTERESTS" => "FU.INTERESTS",
			"FU.SUBSC_GROUP_MESSAGE" => "FU.SUBSC_GROUP_MESSAGE",
			"FU.SUBSC_GET_MY_MESSAGE" => "FU.SUBSC_GET_MY_MESSAGE",
			"FU.LAST_POST" => "FU.LAST_POST",
			"FU.ALLOW_POST" => "FU.ALLOW_POST",
			"FU.SIGNATURE" => "FU.SIGNATURE",
			"FU.RANK_ID" => "FU.RANK_ID",
			"FU.POINTS" => "FU.POINTS",
			"FU.HIDE_FROM_ONLINE" => "FU.HIDE_FROM_ONLINE",
			"U.DATE_REGISTER" => "U.DATE_REGISTER",
			"U.EMAIL" => "U.EMAIL",
			"U.NAME" => "U.NAME",
			"U.SECOND_NAME" => "U.SECOND_NAME",
			"U.LAST_NAME" => "U.LAST_NAME",
			"U.LOGIN" => "U.LOGIN",
			"U.PERSONAL_BIRTHDATE" => "U.PERSONAL_BIRTHDATE",
			"U.PERSONAL_ICQ" => "U.PERSONAL_ICQ",
			"U.PERSONAL_WWW" => "U.PERSONAL_WWW",
			"U.PERSONAL_PROFESSION" => "U.PERSONAL_PROFESSION",
			"U.PERSONAL_CITY" => "U.PERSONAL_CITY",
			"U.PERSONAL_COUNTRY" => "U.PERSONAL_COUNTRY",
			"U.EXTERNAL_AUTH_ID" => "U.EXTERNAL_AUTH_ID",
			"U.PERSONAL_PHOTO" => "U.PERSONAL_PHOTO",
			"U.PERSONAL_GENDER" => "U.PERSONAL_GENDER",
			"DATE_REG" => $DB->DateToCharFunction("FU.DATE_REG", "SHORT"),
			"LAST_VISIT" => $DB->DateToCharFunction("FU.LAST_VISIT", "FULL"),
			"PERSONAL_BIRTHDAY" => $DB->DateToCharFunction("U.PERSONAL_BIRTHDAY", "SHORT"),
			"U.WORK_POSITION" => "U.WORK_POSITION",
			"U.WORK_COMPANY" => "U.WORK_COMPANY"
			);

		foreach ($arFilter as $key => $val)
		{
			$key_res = CForumNew::GetFilterOperation($key);
			$key = mb_strtoupper($key_res["FIELD"]);
			$strNegative = $key_res["NEGATIVE"];
			$strOperation = $key_res["OPERATION"];

			switch ($key)
			{
				case "USER_ID":
					if (intval($val)<=0)
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(U.ID IS NULL OR U.ID<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" U.ID IS NULL OR NOT ":"")."(U.ID ".$strOperation." ".intval($val)." )";
					break;
				case "ID":
				case "RANK_ID":
				case "NUM_POSTS":
					if (intval($val)<=0)
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FU.".$key." IS NULL OR FU.".$key."<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FU.".$key." IS NULL OR NOT ":"")."(FU.".$key." ".$strOperation." ".intval($val)." )";
					break;
				case "SHOW_NAME":
				case "HIDE_FROM_ONLINE":
				case "SUBSC_GROUP_MESSAGE":
				case "SUBSC_GET_MY_MESSAGE":
				case "ALLOW_POST":
					if ($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FU.".$key." IS NULL OR LENGTH(FU.".$key.")<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FU.".$key." IS NULL OR NOT ":"")."(FU.".$key." ".$strOperation." '".$DB->ForSql($val)."' )";
					break;
				case "ACTIVE":
					if ($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(U.".$key." IS NULL OR LEN(U.".$key.")<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" U.".$key." IS NULL OR NOT ":"")."(U.".$key." ".$strOperation." '".$DB->ForSql($val)."' )";
					break;
				case "PERSONAL_BIRTHDATE":
					if ($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(U.PERSONAL_BIRTHDATE IS NULL)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" U.PERSONAL_BIRTHDATE IS NULL OR NOT ":"")."(U.PERSONAL_BIRTHDATE ".$strOperation." '".$DB->ForSql($val)."')";
					break;
				case "PERSONAL_BIRTHDAY":
					if($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(U.PERSONAL_BIRTHDAY IS NULL)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" U.PERSONAL_BIRTHDAY IS NULL OR NOT ":"")."(U.PERSONAL_BIRTHDAY ".$strOperation." ".$DB->CharToDateFunction($DB->ForSql($val), "SHORT").")";
					break;
				case "PERSONAL_BIRTHDAY_DATE":
					$arSqlSearch[] = ($strNegative=="Y"?" U.PERSONAL_BIRTHDAY IS NULL OR NOT ":"")."( ". $sqlHelper->formatDate('MM-DD', 'U.PERSONAL_BIRTHDAY') .") ".$strOperation." '".$DB->ForSql($val)."')";
					break;
				case "LAST_VISIT":
					if($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FU.LAST_VISIT IS NULL)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FU.LAST_VISIT IS NULL OR NOT ":"")."(FU.LAST_VISIT ".$strOperation." ".$DB->CharToDateFunction($DB->ForSql($val), "SHORT").")";
					break;
				case "LOGIN":
				case "EMAIL":
					$arSqlSearch[] = GetFilterQuery("U.".$key, $val);
					break;
				case "NAME":
					$arSqlSearch[] = GetFilterQuery("U.NAME, U.LAST_NAME, U.SECOND_NAME", $val);
					break;
				case"SUBSC_NEW_TOPIC_ONLY":
					$key = "NEW_TOPIC_ONLY";
					$arSqlFrom["FS"] = "INNER JOIN b_forum_subscribe FS ON (FU.USER_ID = FS.USER_ID)";
					if ($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FS.".$key." IS NULL OR LENGTH(FS.".$key.")<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FS.".$key." IS NULL OR NOT ":"")."(FS.".$key." ".$strOperation." '".$DB->ForSql($val)."' )";
					break;
				case "SUBSC_START_DATE":
					$key = "START_DATE";
					$arSqlFrom["FS"] = "INNER JOIN b_forum_subscribe FS ON (FU.USER_ID = FS.USER_ID)";
					if($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FS.".$key." IS NULL)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FS.".$key." IS NULL OR NOT ":"")."(FS.".$key." ".$strOperation." ".$DB->CharToDateFunction($DB->ForSql($val), "SHORT").")";
					break;
				case "SUBSC_FORUM_ID":
				case "SUBSC_TOPIC_ID":
				case "SUBSC":
					$arSqlFrom["FS"] = "INNER JOIN b_forum_subscribe FS ON (FU.USER_ID = FS.USER_ID)";
					unset($arSqlSelectConst["FU.INTERESTS"]);
					$arSqlSelect = $arSqlSelectConst;
					$arSqlSelect["SUBSC_COUNT"] = "COUNT(FS.ID)";
					$arSqlSelect["SUBSC_START_DATE"] = $DB->DateToCharFunction("MIN(FS.START_DATE)", "FULL");
					$arSqlGroup = array_merge($arSqlSelectConst, $arSqlGroup);
					if ($key != "SUBSC")
					{
						$key = mb_substr($key, mb_strlen("SUBSC_"));
						if (intval($val)<=0)
							$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FS.".$key." IS NULL OR FS.".$key."<=0)";
						else
							$arSqlSearch[] = ($strNegative=="Y"?" FS.".$key." IS NULL OR NOT ":"")."(FS.".$key." ".$strOperation." ".intval($val)." )";
					}
					break;
				default:
					if (mb_substr($key, 0, mb_strlen("USER_")) == "USER_")
					{
						$strUserKey = mb_substr($key, mb_strlen("USER_"));
						if (array_key_exists($strUserKey, $arMainUserFields))
						{
							if ($arMainUserFields[$strUserKey]=="I")
								$arSqlSearch[] = ($strNegative=="Y"?" U.".$strUserKey." IS NULL OR NOT ":"")."(U.".$strUserKey." ".$strOperation." ".intval($val)." )";
							elseif ($arMainUserFields[$strUserKey]=="E")
								$arSqlSearch[] = ($strNegative=="Y"?" U.".$strUserKey." IS NULL OR NOT ":"")."(U.".$strUserKey." ".$strOperation." '".$DB->ForSql($val)."' )";
							else
								$arSqlSearch[] = GetFilterQuery("U.".$strUserKey, $val);
						}
					}
			}
		}
		if (count($arSqlSearch) > 0)
			$strSqlSearch = " AND (".implode(") AND (", $arSqlSearch).") ";
		if (count($arSqlSelect) <= 0)
			$arSqlSelect = $arSqlSelectConst;
		foreach ($arSqlSelect as $key => $val)
		{
			if ($val != $key)
				$tmp[] = $val." AS ".$key;
			else
				$tmp[] = $val;
		}
		$strSqlSelect = implode(", ", $tmp);
		if (count($arSqlFrom) > 0)
			$strSqlFrom = implode("	", $arSqlFrom);
		if (count($arSqlGroup) > 0)
			$strSqlGroup = " GROUP BY ".implode(", ", $arSqlGroup);

		foreach ($arOrder as $by=>$order)
		{
			$by = mb_strtoupper($by);
			$order = mb_strtoupper($order);
			if ($order!="ASC")
				$order = "DESC";

			if ($by == "USER_ID") $arSqlOrder[] = " U.ID ".$order." ";
			elseif ($by == "SHOW_NAME") $arSqlOrder[] = " FU.SHOW_NAME ".$order." ";
			elseif ($by == "HIDE_FROM_ONLINE") $arSqlOrder[] = " FU.HIDE_FROM_ONLINE ".$order." ";
			elseif ($by == "SUBSC_GROUP_MESSAGE") $arSqlOrder[] = " FU.SUBSC_GROUP_MESSAGE ".$order." ";
			elseif ($by == "SUBSC_GET_MY_MESSAGE") $arSqlOrder[] = " FU.SUBSC_GET_MY_MESSAGE ".$order." ";
			elseif ($by == "NUM_POSTS") $arSqlOrder[] = " FU.NUM_POSTS ".$order." ";
			elseif ($by == "LAST_POST") $arSqlOrder[] = " FU.LAST_POST ".$order." ";
			elseif ($by == "POINTS") $arSqlOrder[] = " FU.POINTS ".$order." ";
			elseif ($by == "NAME") $arSqlOrder[] = " U.NAME ".$order." ";
			elseif ($by == "LAST_NAME") $arSqlOrder[] = " U.LAST_NAME ".$order." ";
			elseif ($by == "EMAIL") $arSqlOrder[] = " U.EMAIL ".$order." ";
			elseif ($by == "LOGIN") $arSqlOrder[] = " U.LOGIN ".$order." ";
			elseif ($by == "LAST_VISIT") $arSqlOrder[] = " FU.LAST_VISIT ".$order." ";
			elseif ($by == "DATE_REGISTER") $arSqlOrder[] = " U.DATE_REGISTER ".$order." ";
			elseif ($by == "ID") $arSqlOrder[] = " FU.ID ".$order." ";
			elseif (($by == "SUBSC_COUNT") && array_key_exists("FS", $arSqlFrom)) $arSqlOrder[] = " SUBSC_COUNT ".$order." ";
			elseif (($by == "SUBSC_START_DATE") && array_key_exists("FS", $arSqlFrom)) $arSqlOrder[] = " FS.START_DATE ".$order." ";
			elseif (mb_substr($by, 0, mb_strlen("USER_")) == "USER_")
			{
				$strUserBy = mb_substr($by, mb_strlen("USER_"));
				if (array_key_exists($strUserBy, $arMainUserFields))
				{
					$arSqlOrder[] = " U.".$strUserBy." ".$order." ";
				}
			}
			else
			{
				$arSqlOrder[] = " FU.ID ".$order." ";
				$by = "ID";
			}
		}

		DelDuplicateSort($arSqlOrder);
		if (count($arSqlOrder) > 0)
			$strSqlOrder = " ORDER BY ".implode(", ", $arSqlOrder);

			$strSql = "SELECT ".$strSqlSelect." 
				FROM b_forum_user FU
					INNER JOIN b_user U ON (FU.USER_ID = U.ID) 
					".$strSqlFrom."
				WHERE 1 = 1 
					".$strSqlSearch."
					".$strSqlGroup."
					".$strSqlOrder;
		$db_res = $DB->Query($strSql);
		return $db_res;
	}

	public static function SearchUser($template, $arAddParams = array())
	{
		global $DB;
		$template = $DB->ForSql(str_replace("*", "%", $template));
		$arAddParams = (is_array($arAddParams) ? $arAddParams : array($arAddParams));
		$arAddParams["sNameTemplate"] = (is_set($arAddParams, "nameTemplate") ? $arAddParams["nameTemplate"] : $arAddParams["sNameTemplate"]);

		$strSqlSearch =
			"(
				F.SHOW_NAME = 'Y' AND LENGTH(U.NAME) > 0 AND U.NAME LIKE '".$template."'
			)
			OR
			(
				F.SHOW_NAME = 'Y' AND LENGTH(U.NAME) <= 0
				AND
				LENGTH(U.LAST_NAME) > 0 AND U.LAST_NAME LIKE '".$template."'
			)
			OR
			(
				(
					F.SHOW_NAME = 'N' OR F.SHOW_NAME = '' OR (F.SHOW_NAME IS NULL)
					OR
					(
						F.SHOW_NAME = 'Y'
						AND
						LENGTH(TRIM(CONCAT_WS('',".self::GetNameFieldsForQuery($arAddParams["sNameTemplate"]).")))<=0
					)
				)
				AND
				U.LOGIN LIKE '".$template."'
			)";
		if (mb_substr($template, 0, 1) == '%')
			$strSqlSearch =
			"(
				(
					F.SHOW_NAME = 'Y'
					AND
					LENGTH(TRIM(CONCAT_WS('',U.NAME,U.LAST_NAME))) > 0
					AND
					REPLACE(CONCAT_WS(' ',".self::GetNameFieldsForQuery($arAddParams["sNameTemplate"])."), '  ', ' ') LIKE '".$template."'
				)
				OR
				(
					(
						F.SHOW_NAME = 'N' OR F.SHOW_NAME = '' OR (F.SHOW_NAME IS NULL)
						OR
						(
							F.SHOW_NAME = 'Y'
							AND
							LENGTH(TRIM(CONCAT_WS('',".self::GetNameFieldsForQuery($arAddParams["sNameTemplate"])."))) <= 0
						)
					)
					AND
					U.LOGIN LIKE '".$template."'
				)
			)";

		$iCnt = 0;
		if ((isset($arAddParams["bCount"]) && $arAddParams["bCount"]) || is_set($arAddParams, "bDescPageNumbering"))
		{
			$strSql = "SELECT COUNT(U.ID) AS CNT FROM b_user U LEFT JOIN b_forum_user F ON (F.USER_ID = U.ID) WHERE ".$strSqlSearch;
			$db_res = $DB->Query($strSql);
			$iCnt = ($db_res && ($res = $db_res->Fetch()) ? intval($res["CNT"]) : 0);
			if (isset($arAddParams["bCount"]) && $arAddParams["bCount"])
				return $iCnt;
		}

		$strSql =
			"SELECT U.ID, U.NAME, U.SECOND_NAME, U.LAST_NAME, U.LOGIN, F.SHOW_NAME,
				CASE
					WHEN (F.SHOW_NAME = 'Y' AND LENGTH(TRIM(CONCAT_WS('',".self::GetNameFieldsForQuery($arAddParams["sNameTemplate"])."))) > 0)
					THEN TRIM(REPLACE(CONCAT_WS(' ',".self::GetNameFieldsForQuery($arAddParams["sNameTemplate"])."), '  ', ' '))
					ELSE U.LOGIN
				END AS SHOW_ABC
			FROM b_user U
				LEFT JOIN b_forum_user F ON (F.USER_ID = U.ID)
			WHERE ".$strSqlSearch."\n"."ORDER BY SHOW_ABC";
		if (is_set($arAddParams, "bDescPageNumbering")) {
			$db_res =  new CDBResult();
			$db_res->NavQuery($strSql, $iCnt, $arAddParams);
		} else {
			if ($arAddParams["nTopCount"] > 0)
				$strSql .= " LIMIT 0,".$arAddParams["nTopCount"];
			$db_res = $DB->Query($strSql);
		}

		return $db_res;
	}

	/**
	* Converts name template fields from Bitrix name template to SQL query fields
	*
	* @param string $sNameTemplate Bitrix name template (ex: #LAST_NAME# #NAME#). Uses site name template if empty @see CSite::GetNameTemplates
	* @return string (ex: U.LAST_NAME, U.NAME)
	*/
	public static function GetNameFieldsForQuery($sNameTemplate, $userTablePrefix = "U.")
	{
		global $DB;
		$sqlHelper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$sNameTemplate = (empty($sNameTemplate) ? CSite::GetDefaultNameFormat() : $sNameTemplate);
		if (!preg_match("/(#NAME#)|(#LAST_NAME#\,)|(#LAST_NAME#)|(#SECOND_NAME#)|(#NAME_SHORT#)|(#SECOND_NAME_SHORT#)/u", $sNameTemplate, $matches))
			$sNameTemplate = CSite::GetDefaultNameFormat();
		if (mb_strpos($sNameTemplate, "#NOBR#") !== false)
			$sNameTemplate = preg_replace("/\#NOBR\#(.+?)\#\/NOBR\#/u", "\\1", $sNameTemplate);

		preg_match_all("/(#NAME#)|(#LAST_NAME#\,)|(#LAST_NAME#)|(#SECOND_NAME#)|(#NAME_SHORT#)|(#SECOND_NAME_SHORT#)/u", $sNameTemplate, $matches);

		$tmp = array();
		foreach($matches[0] as $val) {
			$pos = mb_strpos($sNameTemplate, $val);
			if ($pos > 0) {
				$tmp[] = "'".$DB->ForSql(mb_substr($sNameTemplate, 0, $pos))."'";
			}
			$tmp[] = str_replace(
				array(
					"#NAME#",
					"#LAST_NAME#,",
					"#LAST_NAME#",
					"#SECOND_NAME#",
					"#NAME_SHORT#",
					"#SECOND_NAME_SHORT#"
				),
				array(
					$userTablePrefix."NAME",
					"case when LENGTH(TRIM(".$userTablePrefix."LAST_NAME)) <= 0 then '' else " . $sqlHelper->getConcatFunction($userTablePrefix.'LAST_NAME', "','") . " END",
					$userTablePrefix."LAST_NAME",
					$userTablePrefix."SECOND_NAME",
					"case when LENGTH(TRIM(".$userTablePrefix."NAME)) <= 0 then '' else " . $sqlHelper->getConcatFunction("SUBSTRING(".$userTablePrefix."NAME,1,1)", "'.'") . " END",
					"case when LENGTH(TRIM(".$userTablePrefix."SECOND_NAME)) <= 0 then '' else " . $sqlHelper->getConcatFunction("SUBSTRING(".$userTablePrefix."SECOND_NAME,1,1)", "'.'") . " END"
				),
				$val
			);
			$sNameTemplate = mb_substr($sNameTemplate, ($pos + mb_strlen($val)));
		}
		if (!empty($sNameTemplate))
			$tmp[] = "'".$DB->ForSql($sNameTemplate)."'";
		$res = implode(",", $tmp);
		return (!empty($res) ? $res : "''");
	}

	public static function GetFormattedNameFieldsForSelect($arParams = array(), $bReturnAll = true)
	{
		$arParams = (is_array($arParams) ? $arParams : array($arParams));
		$arParams["sNameTemplate"] = trim($arParams["sNameTemplate"]);
		$arParams["sUserTablePrefix"] = rtrim((!empty($arParams["sUserTablePrefix"]) ? $arParams["sUserTablePrefix"] : "U"), ".").".";
		$arParams["sForumUserTablePrefix"] = rtrim((!empty($arParams["sForumUserTablePrefix"]) ? $arParams["sForumUserTablePrefix"] : "FU"), ".").".";
		$arParams["sFieldName"] = (!empty($arParams["sFieldName"]) ? $arParams["sFieldName"] : "AUTHOR_NAME_FRMT");
		$arParams["sUserIDFieldName"] = (!empty($arParams["sUserIDFieldName"]) ? $arParams["sUserIDFieldName"] : "F.LAST_POSTER_ID");
		$res = array(
			"select" =>
				"CASE ".
					" WHEN (".
						$arParams["sForumUserTablePrefix"]."USER_ID > 0 ".
						" AND ".
						$arParams["sForumUserTablePrefix"]."SHOW_NAME = 'Y' ".
						" AND ".
						"LENGTH(TRIM(CONCAT_WS('',".
							CForumUser::GetNameFieldsForQuery(
								$arParams["sNameTemplate"],
								$arParams["sUserTablePrefix"])."))) > 0".
					") ".
					" THEN TRIM(REPLACE(CONCAT_WS(' ',".
						CForumUser::GetNameFieldsForQuery(
							$arParams["sNameTemplate"],
							$arParams["sUserTablePrefix"])."), '  ', ' '))".
					" ELSE ".$arParams["sUserTablePrefix"]."LOGIN ".
				" END AS ".$arParams["sFieldName"],
			"join" =>
				"LEFT JOIN b_forum_user ".rtrim($arParams["sForumUserTablePrefix"], ".").
					" ON (".$arParams["sUserIDFieldName"]."=".$arParams["sForumUserTablePrefix"]."USER_ID) ".
				"LEFT JOIN b_user ".rtrim($arParams["sUserTablePrefix"], ".").
					" ON (".$arParams["sUserIDFieldName"]."=".$arParams["sUserTablePrefix"]."ID) "
		);
		if ($bReturnAll)
			return $res;
		return $res["select"];
	}
}

class CForumSubscribe extends CAllForumSubscribe
{
}

class CForumRank extends CAllForumRank
{
	// Tekuwie statusy posetitelej srazu ne pereschityvayutsya. Tol'ko postepenno v processe raboty.
	public static function Add($arFields)
	{
		global $DB;

		if (!CForumRank::CheckFields("ADD", $arFields))
			return false;

		$arInsert = $DB->PrepareInsert("b_forum_rank", $arFields);
		$strSql = "INSERT INTO b_forum_rank(".$arInsert[0].") VALUES(".$arInsert[1].")";
		$DB->Query($strSql);
		$ID = intval($DB->LastID());
		foreach ($arFields["LANG"] as $i => $val)
		{
			$arInsert = $DB->PrepareInsert("b_forum_rank_lang", $arFields["LANG"][$i]);
			$strSql = "INSERT INTO b_forum_rank_lang(RANK_ID, ".$arInsert[0].") VALUES(".$ID.", ".$arInsert[1].")";
			$DB->Query($strSql);
		}
		return $ID;
	}
}

class CForumStat extends CALLForumStat
{
	public static function GetListEx($arOrder = Array("ID"=>"ASC"), $arFilter = Array(), $arAddParams = array())
	{
		global $DB;
		$sqlHelper = \Bitrix\Main\Application::getConnection()->getSqlHelper();
		$arSqlSearch = array();
		$arSqlFrom = array();
		$arSqlOrder = array();
		$strSqlSearch = "";
		$strSqlFrom = "";
		$strSqlOrder = "";
		$arFilter = (is_array($arFilter) ? $arFilter : array());

		foreach ($arFilter as $key => $val)
		{
			$key_res = CForumNew::GetFilterOperation($key);
			$key = mb_strtoupper($key_res["FIELD"]);
			$strNegative = $key_res["NEGATIVE"];
			$strOperation = $key_res["OPERATION"];
			switch ($key)
			{
				case "TOPIC_ID":
				case "FORUM_ID":
					if (intval($val)<=0)
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FSTAT.".$key." IS NULL OR FSTAT.".$key."<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FSTAT.".$key." IS NULL OR NOT ":"")."(FSTAT.".$key." ".$strOperation." ".intval($val).")";
					break;
				case "SITE_ID":
					$bOrNull = false;
					if (is_array($val)):
						$res = array();
						foreach ($val as $v):
							$v = trim($v);
							if ($v == "NULL")
								$bOrNull = true;
							elseif (!empty($v))
								$res[] = "'".$DB->ForSql($v)."'";
						endforeach;
						$val = (!empty($res) ? implode(", ", $res) : "");
						$strOperation = (!empty($res) ? "IN" : $strOperation);
					else:
						$val = "'".$DB->ForSql($val)."'";
					endif;
					if ($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FSTAT.".$key." IS NULL OR LENGTH(FSTAT.".$key.")<=0)";
					elseif ($strOperation == "IN")
						$arSqlSearch[] = ($strNegative=="Y"?" FSTAT.".$key." IS NULL OR NOT ":"")."(FSTAT.".$key." IN (".$val.")".(
							$bOrNull ? " OR (FSTAT.".$key." IS NULL OR LENGTH(FSTAT.".$key.")<=0)" : "").")";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FSTAT.".$key." IS NULL OR NOT ":"")."(FSTAT.".$key." ".$strOperation." ".$val.")";
					break;
				case "LAST_VISIT":
					if($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FSTAT.".$key." IS NULL)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FSTAT.".$key." IS NULL OR NOT ":"")."(FSTAT.".$key." ".$strOperation." ".$DB->CharToDateFunction($DB->ForSql($val), "FULL").")";
					break;
				case "PERIOD":
					if($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FSTAT.LAST_VISIT IS NULL)";
					else
					{
						$arSqlSearch[] = ($strNegative == "Y" ? " FSTAT.LAST_VISIT IS NULL OR NOT " : "") .
							'(' . $sqlHelper->addSecondsToDateTime(-intval($val)) . ' ' . $strOperation . "  FSTAT.LAST_VISIT)";
					}
					break;
				case "HIDE_FROM_ONLINE":
					$arSqlFrom["FU"] = "LEFT JOIN b_forum_user FU ON (FSTAT.USER_ID=FU.USER_ID)";
					if ($val == '')
						$arSqlSearch[] = ($strNegative=="Y"?"NOT":"")."(FU.".$key." IS NULL OR LENGTH(FU.".$key.")<=0)";
					else
						$arSqlSearch[] = ($strNegative=="Y"?" FU.".$key." IS NULL OR NOT ":"")."(((FU.".$key." ".$strOperation." '".$DB->ForSql($val)."' ) AND (FSTAT.USER_ID > 0)) OR (FSTAT.USER_ID <= 0))";
					break;
				case "ACTIVE":
						$arSqlFrom["U"] = "LEFT JOIN b_user U ON (FSTAT.USER_ID=U.ID)";
						$arSqlSearch[] = ($strNegative=="Y"?" U.".$key." IS NULL OR NOT ":"")."(FSTAT.USER_ID = 0 OR U.ACTIVE = 'Y')";
					break;
			}
		}
		if (!empty($arSqlSearch))
			$strSqlSearch = " AND ".implode(" AND ", $arSqlSearch)." ";

		if (!empty($arSqlFrom))
			$strSqlFrom = implode("\n", $arSqlFrom);

		foreach ($arOrder as $by=>$order)
		{
			$by = mb_strtoupper($by);
			$order = mb_strtoupper($order);
			$order = $order!="ASC" ? $order = "DESC" : "ASC";

			if ($by == "USER_ID") $arSqlOrder[] = " FSTAT.USER_ID ".$order." ";
		}

		DelDuplicateSort($arSqlOrder);
		if (count($arSqlOrder) > 0)
			$strSqlOrder = " ORDER BY ".implode(", ", $arSqlOrder);

		$strSql =
			"SELECT FSTAT.USER_ID, FSTAT.IP_ADDRESS, FSTAT.PHPSESSID, \n".
			"	".$DB->DateToCharFunction("FSTAT.LAST_VISIT", "FULL")." AS LAST_VISIT, \n".
			"	FSTAT.FORUM_ID, FSTAT.TOPIC_ID \n".
			"FROM b_forum_stat FSTAT ".$strSqlFrom. "\n".
			"WHERE 1=1 ".$strSqlSearch."\n".
			$strSqlOrder;

		if (is_set($arFilter, "COUNT_GUEST"))
		{
			$strSql =
				"SELECT FST.*, FU.*, FSTAT.IP_ADDRESS, FSTAT.PHPSESSID, \n".
				"	".$DB->DateToCharFunction("FSTAT.LAST_VISIT", "FULL")." AS LAST_VISIT, \n".
				"	FSTAT.FORUM_ID, FSTAT.TOPIC_ID, \n".
				"	U.LOGIN, U.NAME, U.SECOND_NAME, U.LAST_NAME, \n".
				"	".
				(!empty($arAddParams["sNameTemplate"]) ?
					CForumUser::GetFormattedNameFieldsForSelect(
						array_merge(
							$arAddParams,
							array(
								"sUserTablePrefix" => "U.",
								"sForumUserTablePrefix" => "FU.",
								"sFieldName" => "SHOW_NAME")
						),
						false
					) :
					"FSTAT.SHOW_NAME"
				)."\n ".
			" FROM ( ".
				" SELECT FSTAT.USER_ID, MAX(FSTAT.ID) FST_ID, COUNT(FSTAT.PHPSESSID) COUNT_USER ".
				" FROM b_forum_stat FSTAT ".
				$strSqlFrom.
				" WHERE 1=1 ".$strSqlSearch.
				" GROUP BY FSTAT.USER_ID".
			") FST ".
			"LEFT JOIN b_forum_stat FSTAT ON (FST.FST_ID = FSTAT.ID) ".
			"LEFT JOIN b_forum_user FU ON (FST.USER_ID = FU.USER_ID) ".
			"LEFT JOIN b_user U ON (FST.USER_ID = U.ID) ".
			$strSqlOrder;
		}
		$db_res = $DB->Query($strSql);
		return $db_res;
	}
}
