<?php

IncludeModuleLangFile(__FILE__);

class group
{
    public function Add($arFields)
    {
        global $DB;

        if (!$this->CheckFields($arFields, 0)) {
            return false;
        }

        $ID = $DB->Add('b_cluster_group', $arFields);

        return $ID;
    }

    public static function Delete($ID)
    {
        global $DB, $APPLICATION;
        $aMsg = [];
        $ID = (int) $ID;

        $rsWebNodes = CClusterWebnode::GetList([], ['=GROUP_ID' => $ID]);
        if ($rsWebNodes->Fetch()) {
            $aMsg[] = ['text' => GetMessage('CLU_GROUP_HAS_WEBNODE')];
        }

        $rsDBNodes = CClusterDBNode::GetList([], ['=GROUP_ID' => $group_id]);
        if ($rsWebNodes->Fetch()) {
            $aMsg[] = ['text' => GetMessage('CLU_GROUP_HAS_DBNODE')];
        }

        // TODO: memcache check

        if (empty($aMsg)) {
            $res = $DB->Query('DELETE FROM b_cluster_group WHERE ID = '.$ID, false, '', ['fixed_connection' => true]);
        } else {
            $e = new CAdminException($aMsg);
            $APPLICATION->ThrowException($e);

            return false;
        }

        return $res;
    }

    public function Update($ID, $arFields)
    {
        global $DB;
        $ID = (int) $ID;

        if ($ID <= 0) {
            return false;
        }

        if (!$this->CheckFields($arFields, $ID)) {
            return false;
        }

        $strUpdate = $DB->PrepareUpdate('b_cluster_group', $arFields);
        if ('' !== $strUpdate) {
            $strSql = '
				UPDATE b_cluster_group SET
				'.$strUpdate.'
				WHERE ID = '.$ID.'
			';
            if (!$DB->Query($strSql, false, '', ['fixed_connection' => true])) {
                return false;
            }
        }

        return true;
    }

    public static function CheckFields(&$arFields, $ID)
    {
        global $APPLICATION;
        $aMsg = [];

        unset($arFields['ID']);

        $arFields['NAME'] = trim($arFields['NAME']);
        if ('' === $arFields['NAME']) {
            $aMsg[] = ['id' => 'NAME', 'text' => GetMessage('CLU_GROUP_EMPTY_NAME')];
        }

        if (!empty($aMsg)) {
            $e = new CAdminException($aMsg);
            $APPLICATION->ThrowException($e);

            return false;
        }

        return true;
    }

    public static function GetList($arOrder = false, $arFilter = false, $arSelect = false)
    {
        global $DB;

        if (!is_array($arSelect)) {
            $arSelect = [];
        }
        if (count($arSelect) < 1) {
            $arSelect = [
                'ID',
                'NAME',
            ];
        }

        if (!is_array($arOrder)) {
            $arOrder = [];
        }

        $arQueryOrder = [];
        foreach ($arOrder as $strColumn => $strDirection) {
            $strColumn = strtoupper($strColumn);
            $strDirection = 'ASC' === strtoupper($strDirection) ? 'ASC' : 'DESC';

            switch ($strColumn) {
                case 'ID':
                case 'NAME':
                    $arSelect[] = $strColumn;
                    $arQueryOrder[$strColumn] = $strColumn.' '.$strDirection;

                    break;
            }
        }

        $arQuerySelect = [];
        foreach ($arSelect as $strColumn) {
            $strColumn = strtoupper($strColumn);

            switch ($strColumn) {
                case 'ID':
                case 'NAME':
                    $arQuerySelect[$strColumn] = 'g.'.$strColumn;

                    break;
            }
        }
        if (count($arQuerySelect) < 1) {
            $arQuerySelect = ['ID' => 'w.ID'];
        }

        $obQueryWhere = new CSQLWhere();
        $arFields = [
            'ID' => [
                'TABLE_ALIAS' => 'g',
                'FIELD_NAME' => 'g.ID',
                'FIELD_TYPE' => 'int',
                'JOIN' => false,
            ],
        ];
        $obQueryWhere->SetFields($arFields);

        if (!is_array($arFilter)) {
            $arFilter = [];
        }
        $strQueryWhere = $obQueryWhere->GetQuery($arFilter);

        $bDistinct = $obQueryWhere->bDistinctReqired;

        $strSql = '
			SELECT '.($bDistinct ? 'DISTINCT' : '').'
			'.implode(', ', $arQuerySelect).'
			FROM
				b_cluster_group g
			'.$obQueryWhere->GetJoins().'
		';

        if ($strQueryWhere) {
            $strSql .= '
				WHERE
				'.$strQueryWhere.'
			';
        }

        if (count($arQueryOrder) > 0) {
            $strSql .= '
				ORDER BY
				'.implode(', ', $arQueryOrder).'
			';
        }

        return $DB->Query($strSql, false, '', ['fixed_connection' => true]);
    }

    public static function GetArrayByID($ID)
    {
        $rs = CClusterGroup::GetList([], ['=ID' => $ID]);

        return $rs->Fetch();
    }
}
