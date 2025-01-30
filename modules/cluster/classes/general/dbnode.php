<?php

IncludeModuleLangFile(__FILE__);

class dbnode
{
    public static function GetByID($node_id, $arVirtNode = false)
    {
        global $DB, $CACHE_MANAGER;
        static $arNodes = false;
        static $arVirtNodes = [];

        // This code sets and gets virtual nodes
        // needed to test connection just before
        // save node credentials into db
        if (preg_match('/^v(\d+)$/', $node_id)) {
            if (is_array($arVirtNode)) {
                $arVirtNodes[$node_id] = $arVirtNode;

                return true;
            }

            return $arVirtNodes[$node_id];
        }

        // Normal method continues here
        $node_id = (int) $node_id;
        if (false === $arNodes) {
            $cache_id = 'db_nodes';
            if (
                CACHED_b_cluster_dbnode !== false
                && $CACHE_MANAGER->Read(CACHED_b_cluster_dbnode, $cache_id, 'b_cluster_dbnode')
            ) {
                $arNodes = $CACHE_MANAGER->Get($cache_id);
            } else {
                $arNodes = [];

                $rs = $DB->Query('SELECT * FROM b_cluster_dbnode ORDER BY ID', false, '', ['fixed_connection' => true]);
                while ($ar = $rs->Fetch()) {
                    $arNodes[(int) $ar['ID']] = $ar;
                }

                if (CACHED_b_cluster_dbnode !== false) {
                    $CACHE_MANAGER->Set($cache_id, $arNodes);
                }
            }
        }

        return $arNodes[$node_id];
    }

    public function Add($arFields)
    {
        global $DB, $CACHE_MANAGER;

        if (!$this->CheckFields($arFields, 0)) {
            return false;
        }

        if (!array_key_exists('ACTIVE', $arFields)) {
            $arFields['ACTIVE'] = 'Y';
        }

        $ID = $DB->Add('b_cluster_dbnode', $arFields);

        if (CACHED_b_cluster_dbnode !== false) {
            $CACHE_MANAGER->CleanDir('b_cluster_dbnode');
        }

        return $ID;
    }

    public static function Delete($ID, $bStopSlave = true)
    {
        global $DB, $CACHE_MANAGER;
        $ID = (int) $ID;

        $res = $DB->Query('select ID from b_cluster_dbnode WHERE ID=1 OR MASTER_ID = '.$ID, false, '', ['fixed_connection' => true]);
        if ($bStopSlave) {
            while ($ar = $res->Fetch()) {
                if (!CClusterSlave::Stop($ar['ID'])) {
                    return false;
                }
            }
        }

        if ($res) {
            $res = $DB->Query('DELETE FROM b_cluster_dbnode WHERE ID = '.$ID, false, '', ['fixed_connection' => true]);
        }

        if (CACHED_b_cluster_dbnode !== false) {
            $CACHE_MANAGER->CleanDir('b_cluster_dbnode');
        }

        return $res;
    }

    public function Update($ID, $arFields)
    {
        global $DB, $CACHE_MANAGER;
        $ID = (int) $ID;

        if ($ID <= 0) {
            return false;
        }

        if (!$this->CheckFields($arFields, $ID)) {
            return false;
        }

        unset($arFields['GROUP_ID']);

        $strUpdate = $DB->PrepareUpdate('b_cluster_dbnode', $arFields);
        if ('' !== $strUpdate) {
            $strSql = '
				UPDATE b_cluster_dbnode SET
				'.$strUpdate.'
				WHERE ID = '.$ID.'
			';
            if (!$DB->Query($strSql, false, '', ['fixed_connection' => true])) {
                return false;
            }
        }

        if (CACHED_b_cluster_dbnode !== false) {
            $CACHE_MANAGER->CleanDir('b_cluster_dbnode');
        }

        return true;
    }

    public static function SetOffline($node_id)
    {
        global $DB, $CACHE_MANAGER, $APPLICATION;

        $rs = $DB->Query("
			UPDATE b_cluster_dbnode SET
			STATUS = 'OFFLINE'
			WHERE ID = ".(int) $node_id."
			AND STATUS <> 'OFFLINE'
		", false, '', ['fixed_connection' => true]);
        if ($rs->AffectedRowsCount() > 0) {
            if (CACHED_b_cluster_dbnode !== false) {
                $CACHE_MANAGER->CleanDir('b_cluster_dbnode');
            }

            if (!CAgent::AddAgent('CClusterDBNode::BringOnline();', 'cluster', 'N', 10)) {
                $APPLICATION->ResetException();
            }
        }
    }

    public static function SetOnline($node_id)
    {
        global $DB, $CACHE_MANAGER;

        $DB->Query("
			UPDATE b_cluster_dbnode SET
			STATUS = 'ONLINE'
			WHERE ID = ".(int) $node_id.'
		', false, '', ['fixed_connection' => true]);

        if (CACHED_b_cluster_dbnode !== false) {
            $CACHE_MANAGER->CleanDir('b_cluster_dbnode');
        }
    }

    public static function BringOnline()
    {
        $rsOfflineNodes = CClusterDBNode::GetList([], ['=STATUS' => 'OFFLINE'], ['ID']);
        if ($arNode = $rsOfflineNodes->Fetch()) {
            ob_start();
            $nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true, false);
            ob_end_clean();
            if (is_object($nodeDB)) {
                CClusterDBNode::SetOnline($arNode['ID']);
            }

            return 'CClusterDBNode::BringOnline();';
        }

        return '';
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
                'ACTIVE',
                'ROLE_ID',
                'NAME',
                'DESCRIPTION',
                'DB_HOST',
                'DB_NAME',
                'DB_LOGIN',
                'DB_PASSWORD',
                'MASTER_ID',
                'SERVER_ID',
                'STATUS',
                'WEIGHT',
                'SELECTABLE',
                'GROUP_ID',
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
                case 'ACTIVE':
                case 'ROLE_ID':
                case 'NAME':
                case 'DESCRIPTION':
                case 'DB_HOST':
                case 'DB_NAME':
                case 'DB_LOGIN':
                case 'DB_PASSWORD':
                case 'MASTER_ID':
                case 'SERVER_ID':
                case 'STATUS':
                case 'WEIGHT':
                case 'SELECTABLE':
                case 'GROUP_ID':
                    $arQuerySelect[$strColumn] = 'n.'.$strColumn;

                    break;
            }
        }
        if (count($arQuerySelect) < 1) {
            $arQuerySelect = ['ID' => 'n.ID'];
        }

        $obQueryWhere = new CSQLWhere();
        $arFields = [
            'ID' => [
                'TABLE_ALIAS' => 'n',
                'FIELD_NAME' => 'n.ID',
                'FIELD_TYPE' => 'int',
                'JOIN' => false,
            ],
            'GROUP_ID' => [
                'TABLE_ALIAS' => 'n',
                'FIELD_NAME' => 'n.GROUP_ID',
                'FIELD_TYPE' => 'string',
                'JOIN' => false,
            ],
            'ROLE_ID' => [
                'TABLE_ALIAS' => 'n',
                'FIELD_NAME' => 'n.ROLE_ID',
                'FIELD_TYPE' => 'string',
                'JOIN' => false,
            ],
            'ACTIVE' => [
                'TABLE_ALIAS' => 'n',
                'FIELD_NAME' => 'n.ACTIVE',
                'FIELD_TYPE' => 'string',
                'JOIN' => false,
            ],
            'SERVER_ID' => [
                'TABLE_ALIAS' => 'n',
                'FIELD_NAME' => 'n.SERVER_ID',
                'FIELD_TYPE' => 'int',
                'JOIN' => false,
            ],
            'MASTER_ID' => [
                'TABLE_ALIAS' => 'n',
                'FIELD_NAME' => 'n.MASTER_ID',
                'FIELD_TYPE' => 'int',
                'JOIN' => false,
            ],
            'STATUS' => [
                'TABLE_ALIAS' => 'n',
                'FIELD_NAME' => 'n.STATUS',
                'FIELD_TYPE' => 'string',
                'JOIN' => false,
            ],
            'NAME' => [
                'TABLE_ALIAS' => 'n',
                'FIELD_NAME' => 'n.NAME',
                'FIELD_TYPE' => 'string',
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
				b_cluster_dbnode n
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

    public static function getServerList()
    {
        global $DB;
        $result = [];
        $rsData = $DB->Query('
			SELECT ID, GROUP_ID, ROLE_ID, DB_HOST
			FROM b_cluster_dbnode
			ORDER BY GROUP_ID, ID
		');
        while ($arData = $rsData->Fetch()) {
            if ('SLAVE' === $arData['ROLE_ID'] || 'MASTER' === $arData['ROLE_ID']) {
                $edit_url = '/bitrix/admin/cluster_slave_edit.php?lang='.LANGUAGE_ID.'&group_id='.$arData['GROUP_ID'].'&ID='.$arData['ID'];
            } elseif ('MODULE' === $arData['ROLE_ID']) {
                $edit_url = '/bitrix/admin/cluster_dbnode_edit.php?lang='.LANGUAGE_ID.'&ID='.$arData['ID'];
            } else {
                $edit_url = '';
            }

            $host = '';
            $match = [];
            if (
                preg_match('/\\(\\s*HOST\\s*=\\s*([^)]+)\\)/i', $arData['DB_HOST'], $match)
                || preg_match('/^([^:]+)(:|)/', $arData['DB_HOST'], $match)
            ) {
                if ('localhost' !== $match[1] && '127.0.0.1' !== $match[1]) {
                    $host = $arData['DB_HOST'];
                }
            }

            $result[] = [
                'ID' => $arData['ID'],
                'GROUP_ID' => $arData['GROUP_ID'],
                'SERVER_TYPE' => 'database',
                'ROLE_ID' => $arData['ROLE_ID'],
                'HOST' => $arData['DB_HOST'],
                'DEDICATED' => '' === $host ? 'N' : 'Y',
                'EDIT_URL' => $edit_url,
            ];
        }

        return $result;
    }

    public static function GetModules($node_id)
    {
        global $DB;
        static $arCache = false;
        if (false === $arCache) {
            $arCache = [];
            $rs = $DB->Query("SELECT * from b_option WHERE NAME='dbnode_id'", false, '', ['fixed_connection' => true]);
            while ($ar = $rs->Fetch()) {
                if (is_numeric($ar['VALUE'])) {
                    $arCache[(int) $ar['VALUE']][$ar['MODULE_ID']] = $ar['MODULE_ID'];
                }
            }
        }
        if (isset($arCache[$node_id])) {
            return $arCache[$node_id];
        }

        return [];
    }

    public static function GetListForModuleInstall()
    {
        return CClusterDBNode::GetList(
            ['NAME' => 'ASC', 'ID' => 'ASC'],
            ['=ACTIVE' => 'Y', '=ROLE_ID' => 'MODULE', '=STATUS' => 'READY'],
            ['ID', 'NAME']
        );
    }
}
