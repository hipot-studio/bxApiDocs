<?php

/**
 * Bitrix Framework.
 *
 * @copyright 2001-2013 Bitrix
 */
class blog_post_param
{
    protected static $__USER_OPTIONS_CACHE;

    public static function GetList($arOrder = ['ID' => 'ASC'], $arFilter = [], $arAddParams = [])
    {
        global $DB;

        $arFields = [
            'ID' => ['FIELD' => 'BPP.ID', 'TYPE' => 'int'],
            'POST_ID' => ['FIELD' => 'BPP.POST_ID', 'TYPE' => 'int'],
            'USER_ID' => ['FIELD' => 'BPP.USER_ID', 'TYPE' => 'int'],
            'NAME' => ['FIELD' => 'BPP.NAME', 'TYPE' => 'string'],
            'VALUE' => ['FIELD' => 'BPP.VALUE', 'TYPE' => 'string'],
            'RANK' => ($arOrder['OWNER_ID'] > 0 ? [
                'FIELD' => 'RV0.RANK',
                'TYPE' => 'int',
                'FROM' => "\n\tLEFT JOIN (\n\t\t".
                        "SELECT MAX(RV2.VOTE_WEIGHT) as VOTE_WEIGHT, RV2.ENTITY_ID \n\t\t".
                        "FROM b_rating_user RV2 \n\t\t".
                        "GROUP BY RV2.ENTITY_ID) RV ON (RV.ENTITY_ID = BPP.USER_ID)\n\t".
                    "LEFT JOIN (\n\t\t".
                        "SELECT RV1.OWNER_ID, SUM(case when RV1.ID is not null then 1 else 0 end) as RANK \n\t\t".
                        "FROM b_rating_vote RV1 \n\t\t".
                        'WHERE RV1.USER_ID = '.$arOrder['OWNER_ID']."\n\t\t".
                        'GROUP BY RV1.OWNER_ID) RV0 ON (RV0.OWNER_ID = BPP.USER_ID)',
            ] : [
                'FIELD' => 'RV.RANK',
                'TYPE' => 'string',
                'FROM' => "\n\tLEFT JOIN (".
                    "\n\t\tSELECT MAX(RV2.VOTE_WEIGHT) as VOTE_WEIGHT, RV2.ENTITY_ID, 0 as RANK ".
                    "\n\t\tFROM b_rating_user RV2".
                    "\n\t\tGROUP BY RV2.ENTITY_ID) RV ON (RV.ENTITY_ID = BPP.USER_ID)"]),
            'USER_ACTIVE' => ['FIELD' => 'U.ACTIVE', 'TYPE' => 'string', 'FROM' => "\n\tINNER JOIN b_user U ON (BPP.USER_ID = U.ID)"],
            'USER_NAME' => ['FIELD' => 'U.NAME', 'TYPE' => 'string', 'FROM' => "\n\tINNER JOIN b_user U ON (BPP.USER_ID = U.ID)"],
            'USER_LAST_NAME' => ['FIELD' => 'U.LAST_NAME', 'TYPE' => 'string', 'FROM' => "\n\tINNER JOIN b_user U ON (BPP.USER_ID = U.ID)"],
            'USER_SECOND_NAME' => ['FIELD' => 'U.SECOND_NAME', 'TYPE' => 'string', 'FROM' => "\n\tINNER JOIN b_user U ON (BPP.USER_ID = U.ID)"],
            'USER_LOGIN' => ['FIELD' => 'U.LOGIN', 'TYPE' => 'string', 'FROM' => "\n\tINNER JOIN b_user U ON (BPP.USER_ID = U.ID)"],
            'USER_PERSONAL_PHOTO' => ['FIELD' => 'U.PERSONAL_PHOTO', 'TYPE' => 'string', 'FROM' => "\n\tINNER JOIN b_user U ON (BPP.USER_ID = U.ID)"],
        ];
        $arSelect = array_diff(array_keys($arFields), ['RANK']);
        $arSelect = (is_array($arAddParams['SELECT']) && !empty($arAddParams['SELECT']) ? array_intersect($arAddParams['SELECT'], $arSelect) : $arSelect);

        $arSql = CBlog::PrepareSql($arFields, [], $arFilter, false, $arSelect);

        $arSql['SELECT'] = str_replace('%%_DISTINCT_%%', '', $arSql['SELECT']);

        $iCnt = 0;
        if ($arAddParams['bCount'] || array_key_exists('bDescPageNumbering', $arAddParams)) {
            $strSql = "SELECT COUNT(BPP.ID) AS CNT  \n".
                'FROM b_blog_post_param BPP '.$arSql['FROM']."\n".
                (empty($arSql['GROUPBY']) ? '' : 'GROUP BY '.$arSql['GROUPBY']."\n").
                'WHERE '.(empty($arSql['WHERE']) ? '1 = 1' : $arSql['WHERE']);
            $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
            if ($arAddParams['bCount']) {
                return $db_res;
            }
            $iCnt = ($db_res && ($res = $db_res->Fetch()) ? (int) ($res['CNT']) : 0);
        }

        // ORDER BY -->
        $arSqlOrder = [];
        foreach ($arOrder as $by => $order) {
            $by = strtoupper($by);
            $order = ('ASC' !== strtoupper($order) ? 'DESC' : 'ASK');
            if (array_key_exists($by, $arFields) && !array_key_exists($by, $arSqlOrder)) {
                if ('ORACLE' === strtoupper($DB->type)) {
                    $order .= ('ASC' === $order ? ' NULLS FIRST' : ' NULLS LAST');
                }

                if (isset($arFields[$by]['FROM']) && !empty($arFields[$by]['FROM']) && !str_contains($arSql['FROM'], $arFields[$by]['FROM'])) {
                    $arSql['FROM'] .= ' '.$arFields[$by]['FROM'];
                }
                if ('RANK' === $by) {
                    $arSql['SELECT'] .= ', '.$arFields['RANK']['FIELD'];
                    $arSqlOrder[$by] = (IsModuleInstalled('intranet') ? 'RV.VOTE_WEIGHT '.$order.', RANK '.$order :
                        'RANK '.$order.', RV.VOTE_WEIGHT '.$order);
                } else {
                    $arSqlOrder[$by] = (array_key_exists('ORDER', $arFields[$by]) ? $arFields[$by]['ORDER'] : $arFields[$by]['FIELD']).' '.$order;
                }
            }
        }
        DelDuplicateSort($arSqlOrder);
        $arSql['ORDERBY'] = implode(', ', $arSqlOrder);
        // <-- ORDER BY

        $strSql =
            'SELECT '.$arSql['SELECT']."\n".
            'FROM b_blog_post_param BPP'.$arSql['FROM']."\n".
            'WHERE '.(empty($arSql['WHERE']) ? '1 = 1' : $arSql['WHERE']).
            (empty($arSql['ORDERBY']) ? '' : "\nORDER BY ".$arSql['ORDERBY']);

        if (is_set($arAddParams, 'bDescPageNumbering')) {
            $db_res = new CDBResult();
            $db_res->NavQuery($strSql, $iCnt, $arAddParams);
        } else {
            $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        }

        return $db_res;
    }

    public static function GetOption($post_id, $name, $default_value = false, $user_id = false)
    {
        global $DB, $USER, $CACHE_MANAGER;

        $post_id = (int) $post_id;
        if (false === $user_id) {
            $user_id = $USER->GetID();
        }
        $user_id = (int) $user_id;
        $cache_key = $post_id.':'.$name;

        if (!isset(self::$__USER_OPTIONS_CACHE[$user_id])) {
            $mcache_id = "user_option:{$user_id}";
            if ($CACHE_MANAGER->read(3_600, $mcache_id, 'blog_post_param') && false) {
                self::$__USER_OPTIONS_CACHE[$user_id] = $CACHE_MANAGER->get($mcache_id);
            } else {
                $strSql = '
					SELECT POST_ID, USER_ID, NAME, VALUE
					FROM b_blog_post_param
					WHERE (USER_ID='.$user_id.' OR USER_ID IS NULL)';
                $db_res = $DB->Query($strSql);

                while ($res = $db_res->Fetch()) {
                    $row_cache_key = $res['POST_ID'].':'.$res['NAME'];
                    $res['USER_ID'] = (int) $res['USER_ID'];

                    if (!isset(self::$__USER_OPTIONS_CACHE[$res['USER_ID']][$row_cache_key])) {
                        self::$__USER_OPTIONS_CACHE[$res['USER_ID']][$row_cache_key] = $res['VALUE'];
                    }
                }
                $CACHE_MANAGER->Set($mcache_id, self::$__USER_OPTIONS_CACHE[$user_id]);
            }
        }
        if (!isset(self::$__USER_OPTIONS_CACHE[$user_id][$cache_key])) {
            return $default_value;
        }

        return self::$__USER_OPTIONS_CACHE[$user_id][$cache_key];
    }

    public static function SetOption($post_id, $name, $value, $user_id = false)
    {
        global $DB, $USER;

        $post_id = (int) $post_id;
        if (false === $user_id) {
            $user_id = $USER->GetID();
        }

        $user_id = (int) $user_id;
        $arFields = [
            'POST_ID' => ($post_id > 0 ? $post_id : false),
            'USER_ID' => ($user_id > 0 ? $user_id : false),
            'NAME' => $name,
            'VALUE' => $value,
        ];
        $res = $DB->Query(
            'SELECT ID FROM b_blog_post_param
			WHERE
			'.($post_id <= 0 ? 'POST_ID IS NULL' : 'POST_ID='.$post_id).' AND
			'.($user_id <= 0 ? 'USER_ID IS NULL' : 'USER_ID='.$user_id)." AND
			NAME='".$DB->ForSql($name, 50)."'"
        );
        if ($res_array = $res->Fetch()) {
            $strUpdate = $DB->PrepareUpdate('b_blog_post_param', $arFields);
            if ('' !== $strUpdate) {
                $strSql = 'UPDATE b_blog_post_param SET '.$strUpdate.' WHERE ID='.$res_array['ID'];
                if (!$DB->QueryBind($strSql, [])) {
                    return false;
                }
            }
        } else {
            if (!$DB->Add('b_blog_post_param', $arFields, [])) {
                return false;
            }
        }

        self::_clear_cache($user_id);

        return true;
    }

    public static function DeleteOption($post_id, $name, $user_id = false)
    {
        global $DB, $USER;
        $post_id = (int) $post_id;
        if (false === $user_id) {
            $user_id = $USER->GetID();
        }
        $user_id = (int) $user_id;

        $strSql = '
			DELETE FROM b_blog_post_param
			WHERE
				'.($post_id <= 0 ? 'POST_ID IS NULL ' : 'POST_ID='.$post_id).'
			AND '.($user_id <= 0 ? 'USER_ID IS NULL ' : 'USER_ID='.$user_id)."
			AND NAME='".$DB->ForSql($name, 50)."'
		";
        if ($DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__)) {
            self::_clear_cache($user_id);

            return true;
        }

        return false;
    }

    public static function DeleteUsersOptions($user_id = false)
    {
        global $DB;
        $user_id = (int) $user_id;
        if ($DB->Query('DELETE FROM b_blog_post_param WHERE '.($user_id <= 0 ? 'USER_ID IS NULL' : 'USER_ID='.$user_id), false, 'File: '.__FILE__.'<br>Line: '.__LINE__)) {
            self::_clear_cache($user_id);

            return true;
        }

        return false;
    }

    // *****************************
    // Events
    // *****************************

    // user deletion event
    public static function OnUserDelete($user_id)
    {
        global $DB;
        $user_id = (int) $user_id;

        if ($DB->Query('DELETE FROM b_user_option WHERE USER_ID='.$user_id, false, 'File: '.__FILE__.'<br>Line: '.__LINE__)) {
            self::_clear_cache($user_id);

            return true;
        }

        return false;
    }

    protected static function _clear_cache($user_id = 0)
    {
        global $CACHE_MANAGER;

        self::$__USER_OPTIONS_CACHE = [];

        if ($user_id > 0) {
            $CACHE_MANAGER->cleanDir('blog_post_param');
        } else {
            $CACHE_MANAGER->clean("user_option:{$user_id}", 'blog_post_param');
        }
    }
}
