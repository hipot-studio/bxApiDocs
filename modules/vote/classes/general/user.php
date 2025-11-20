<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage vote
 * @copyright 2001-2025 Bitrix
 */

class CAllVoteUser
{
	public static function OnUserLogin()
	{
		$_SESSION["VOTE"] = array("VOTES" => array());
	}

	public static function Delete($USER_ID)
	{
		global $DB;
		$USER_ID = intval($USER_ID);
		if ($USER_ID<=0) return;
		$strSql = "DELETE FROM b_vote_user WHERE ID=$USER_ID";
		$res = $DB->Query($strSql);
		return $res;
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
	public static function GetList($by = 's_id', $order = 'desc', $arFilter = [])
	{
		global $DB;

		$arSqlSearch = Array();
		$str_table = "";
		$left_join = "";
		if (is_array($arFilter))
		{
			$filter_keys = array_keys($arFilter);
			foreach ($arFilter as $key => $val)
			{
				if(is_array($val))
				{
					if(count($val) <= 0)
						continue;
				}
				else
				{
					if( ((string)$val == '') || ($val === "NOT_REF") )
						continue;
				}
				$match_value_set = (in_array($key."_EXACT_MATCH", $filter_keys)) ? true : false;
				$key = strtoupper($key);
				switch($key)
				{
					case "ID":
						$match = ($arFilter[$key."_EXACT_MATCH"]=="N" && $match_value_set) ? "Y" : "N";
						$arSqlSearch[] = GetFilterQuery("U.ID",$val,$match);
						break;
					case "DATE_START_1":
						$arSqlSearch[] = "U.DATE_FIRST>=".$DB->CharToDateFunction($val, "SHORT");
						break;
					case "DATE_START_2":
						$arSqlSearch[] = "U.DATE_FIRST<=".$DB->CharToDateFunction($val." 23:59:59", "FULL");
						break;
					case "DATE_END_1":
						$arSqlSearch[] = "U.DATE_LAST>=".$DB->CharToDateFunction($val, "SHORT");
						break;
					case "DATE_END_2":
						$arSqlSearch[] = "U.DATE_LAST<=".$DB->CharToDateFunction($val." 23:59:59", "FULL");
						break;
					case "COUNTER_1":
						$arSqlSearch[] = "U.COUNTER>='".intval($val)."'";
						break;
					case "COUNTER_2":
						$arSqlSearch[] = "U.COUNTER<='".intval($val)."'";
						break;
					case "USER":
						$match = ($arFilter[$key."_EXACT_MATCH"]=="Y" && $match_value_set) ? "N" : "Y";
						$arSqlSearch[] = GetFilterQuery("U.AUTH_USER_ID,A.LOGIN,A.LAST_NAME,A.NAME",$val,$match);
						$left_join = "LEFT JOIN b_user A ON (A.ID=U.AUTH_USER_ID)";
						break;
					case "GUEST":
						$match = ($arFilter[$key."_EXACT_MATCH"]=="N" && $match_value_set) ? "Y" : "N";
						$arSqlSearch[] = GetFilterQuery("U.STAT_GUEST_ID",$val,$match);
						break;
					case "IP":
						$match = ($arFilter[$key."_EXACT_MATCH"]=="Y" && $match_value_set) ? "N" : "Y";
						$arSqlSearch[] = GetFilterQuery("U.LAST_IP",$val,$match,array("."));
						break;
					case "VOTE":
						$str_table = "
							INNER JOIN b_vote_event E ON (E.VOTE_USER_ID = U.ID)
							INNER JOIN b_vote V ON (V.ID = E.VOTE_ID)
							";
						$match = ($arFilter[$key."_EXACT_MATCH"]=="Y" && $match_value_set) ? "N" : "Y";
						$arSqlSearch[] = GetFilterQuery("E.VOTE_ID, V.TITLE",$val,$match);
						break;
					case "VOTE_ID":
						$str_table = "
							INNER JOIN b_vote_event E ON (E.VOTE_USER_ID = U.ID)
							INNER JOIN b_vote V ON (V.ID = E.VOTE_ID)
							";
						$match = ($arFilter[$key."_EXACT_MATCH"]=="N" && $match_value_set) ? "Y" : "N";
						$arSqlSearch[] = GetFilterQuery("E.VOTE_ID",$val,$match);
						break;
				}
			}
		}

		if ($by == "s_id")					$strSqlOrder = "ORDER BY U.ID";
		elseif ($by == "s_date_start")		$strSqlOrder = "ORDER BY U.DATE_FIRST";
		elseif ($by == "s_date_end")		$strSqlOrder = "ORDER BY U.DATE_LAST";
		elseif ($by == "s_counter")			$strSqlOrder = "ORDER BY U.COUNTER";
		elseif ($by == "s_user")			$strSqlOrder = "ORDER BY U.AUTH_USER_ID";
		elseif ($by == "s_stat_guest_id")			$strSqlOrder = "ORDER BY U.STAT_GUEST_ID";
		elseif ($by == "s_ip")				$strSqlOrder = "ORDER BY U.LAST_IP";
		else 
		{
			$strSqlOrder = "ORDER BY U.ID";
		}

		if ($order != "asc")
		{
			$strSqlOrder .= " desc ";
		}

		$strSqlSearch = GetFilterSqlSearch($arSqlSearch);
		$strSql = "
		SELECT VU.ID, U.STAT_GUEST_ID, U.AUTH_USER_ID, U.COUNTER, U.LAST_IP,
			".$DB->DateToCharFunction("U.DATE_FIRST")."	DATE_FIRST,
			".$DB->DateToCharFunction("U.DATE_LAST")."	DATE_LAST,
			BUSER.LOGIN, BUSER.NAME, BUSER.LAST_NAME, BUSER.SECOND_NAME, BUSER.PERSONAL_PHOTO
		FROM (
			SELECT U.ID
			FROM b_vote_user U
				$str_table
				$left_join
			WHERE $strSqlSearch
			GROUP BY U.ID
		) VU
		LEFT JOIN b_vote_user U ON (VU.ID = U.ID)
		LEFT JOIN b_user BUSER ON (U.AUTH_USER_ID = BUSER.ID)
		".$strSqlOrder;
		$res = $DB->Query($strSql);

		return $res;
	}
}
