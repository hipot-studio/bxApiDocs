<?php

IncludeModuleLangFile(__FILE__);

class slave
{
    public static function SetOnLine($node_id, $master_id)
    {
        global $DB;

        $arNode = CClusterDBNode::GetByID($node_id);
        if (!is_array($arNode)) {
            return;
        }

        if ('SLAVE' === $arNode['ROLE_ID']) {
            if (1 === $master_id) {
                $masterDB = $DB;
            } else {
                ob_start();
                $masterDB = CDatabase::GetDBNodeConnection($master_id, true);
                $error = ob_get_contents();
                ob_end_clean();
            }

            $rs = $masterDB->Query('show master status', false, '', ['fixed_connection' => true]);
            if ($arMasterStatus = $rs->Fetch()) {
                ob_start();
                $nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true);
                $error = ob_get_contents();
                ob_end_clean();
                if (is_object($nodeDB)) {
                    $rs = $nodeDB->Query("
						CHANGE MASTER TO
							MASTER_HOST = '".$DB->ForSQL($arNode['MASTER_HOST'])."'
							,MASTER_USER = '".$DB->ForSQL($masterDB->DBLogin)."'
							,MASTER_PASSWORD = '".$DB->ForSQL($masterDB->DBPassword)."'
							,MASTER_PORT = ".$DB->ForSQL($arNode['MASTER_PORT'])."
							,MASTER_LOG_FILE = '".$arMasterStatus['File']."'
							,MASTER_LOG_POS = ".$arMasterStatus['Position'].'
					', false, '', ['fixed_connection' => true]);

                    if ($rs) {
                        $rs = $nodeDB->Query('START SLAVE');
                    }

                    if ($rs) {
                        $obNode = new CClusterDBNode();
                        $obNode->Update($node_id, ['MASTER_ID' => $master_id]);

                        CClusterDBNode::SetOnline($node_id);
                        CClusterSlave::AdjustServerID($arNode, $nodeDB);
                    }
                }
            }
        } elseif ('MASTER' === $arNode['ROLE_ID'] && preg_match('/^(.+):(\\d+)$/', $arNode['DB_HOST'], $match)) {
            $rs = $DB->Query('show master status', false, '', ['fixed_connection' => true]);
            if ($arMasterStatus = $rs->Fetch()) {
                ob_start();
                $nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true);
                $error = ob_get_contents();
                ob_end_clean();
                if (is_object($nodeDB)) {
                    $rs = $nodeDB->Query('STOP SLAVE', true, '', ['fixed_connection' => true]);

                    if ($rs) {
                        $rs = $nodeDB->Query("
							CHANGE MASTER TO
								MASTER_HOST = '".$DB->ForSQL($arNode['MASTER_HOST'])."'
								,MASTER_USER = '".$DB->ForSQL($DB->DBLogin)."'
								,MASTER_PASSWORD = '".$DB->ForSQL($DB->DBPassword)."'
								,MASTER_PORT = ".$DB->ForSQL($arNode['MASTER_PORT'])."
								,MASTER_LOG_FILE = '".$arMasterStatus['File']."'
								,MASTER_LOG_POS = ".$arMasterStatus['Position'].'
						', false, '', ['fixed_connection' => true]);
                    }

                    if ($rs) {
                        $rs = $nodeDB->Query('START SLAVE');
                    }

                    if ($rs) {
                        $rs = $nodeDB->Query('show master status', false, '', ['fixed_connection' => true]);
                        if ($arMasterStatus = $rs->Fetch()) {
                            $rs = $DB->Query('STOP SLAVE', true, '', ['fixed_connection' => true]);

                            if ($rs) {
                                $rs = $DB->Query("
									CHANGE MASTER TO
										MASTER_HOST = '".$DB->ForSQL($match[1])."'
										,MASTER_USER = '".$DB->ForSQL($arNode['DB_LOGIN'])."'
										,MASTER_PASSWORD = '".$DB->ForSQL($arNode['DB_PASSWORD'])."'
										,MASTER_PORT = ".$DB->ForSQL($match[2])."
										,MASTER_LOG_FILE = '".$arMasterStatus['File']."'
										,MASTER_LOG_POS = ".$arMasterStatus['Position'].'
								', false, '', ['fixed_connection' => true]);
                            }

                            if ($rs) {
                                $rs = $DB->Query('START SLAVE');
                            }

                            if ($rs) {
                                $obNode = new CClusterDBNode();
                                $obNode->Update($node_id, ['MASTER_ID' => $master_id]);
                                $obNode->Update($master_id, ['MASTER_ID' => $node_id]);

                                CClusterDBNode::SetOnline($node_id);
                                CClusterSlave::AdjustServerID($arNode, $nodeDB);
                            }
                        }
                    }
                }
            }
        }
    }

    public static function Pause($node_id)
    {
        global $DB;

        $arNode = CClusterDBNode::GetByID($node_id);
        if (!is_array($arNode)) {
            return;
        }

        if (1 === $node_id) {
            $nodeDB = $DB;
        } else {
            ob_start();
            $nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true);
            $error = ob_get_contents();
            ob_end_clean();
        }

        if (!is_object($nodeDB)) {
            return;
        }

        $rs = $nodeDB->Query('STOP SLAVE SQL_THREAD', false, '', ['fixed_connection' => true]);
        if ($rs) {
            $ob = new CClusterDBNode();
            $ob->Update($arNode['ID'], ['STATUS' => 'PAUSED']);
        }
    }

    public static function Resume($node_id)
    {
        global $DB;

        $arNode = CClusterDBNode::GetByID($node_id);
        if (!is_array($arNode)) {
            return;
        }

        if (1 === $node_id) {
            $nodeDB = $DB;
        } else {
            ob_start();
            $nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true, false);
            $error = ob_get_contents();
            ob_end_clean();
        }

        if (!is_object($nodeDB)) {
            return;
        }

        $rs = $nodeDB->Query('START SLAVE', false, '', ['fixed_connection' => true]);
        if ($rs) {
            $ob = new CClusterDBNode();
            $ob->Update($arNode['ID'], ['STATUS' => 'ONLINE']);
        }
    }

    public static function Stop($node_id)
    {
        global $DB;

        $arNode = CClusterDBNode::GetByID($node_id);
        if (!is_array($arNode)) {
            return false;
        }

        if (1 === $node_id) {
            $nodeDB = $DB;
        } else {
            ob_start();
            $nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true, false);
            $error = ob_get_contents();
            ob_end_clean();
        }

        if (!is_object($nodeDB)) {
            return false;
        }

        $rs = $nodeDB->Query('STOP SLAVE', false, '', ['fixed_connection' => true]);
        if ($rs) {
            $ob = new CClusterDBNode();
            if (1 === $node_id) {
                $res = $ob->Update($arNode['ID'], ['MASTER_ID' => false, 'STATUS' => 'ONLINE']);
            } else {
                $res = $ob->Update($arNode['ID'], ['STATUS' => 'READY']);
            }

            return $res;
        }

        return false;
    }

    public static function SkipSQLError($node_id)
    {
        global $DB;

        $arNode = CClusterDBNode::GetByID($node_id);
        if (is_array($arNode)) {
            if (1 === $node_id) {
                $nodeDB = $DB;
            } else {
                ob_start();
                $nodeDB = CDatabase::GetDBNodeConnection($arNode['ID'], true, false);
                $error = ob_get_contents();
                ob_end_clean();
            }

            if (is_object($nodeDB)) {
                // TODO check if started just make active
                $rs = $nodeDB->Query('STOP SLAVE', false, '', ['fixed_connection' => true]);
                if ($rs) {
                    $rs = $nodeDB->Query('SET GLOBAL SQL_SLAVE_SKIP_COUNTER = 1', false, '', ['fixed_connection' => true]);
                }
                if ($rs) {
                    $rs = $nodeDB->Query('START SLAVE', false, '', ['fixed_connection' => true]);
                }
            }
        }
    }

    public static function GetStatus($node_id, $bSlaveStatus = true, $bGlobalStatus = true, $bVariables = true)
    {
        global $DB;

        $arNode = CClusterDBNode::GetByID($node_id);
        if (!is_array($arNode)) {
            return false;
        }

        if (1 === $node_id) {
            $nodeDB = $DB;
        } else {
            ob_start();
            $nodeDB = CDatabase::GetDBNodeConnection($node_id, true, false);
            $error = ob_get_contents();
            ob_end_clean();
        }

        if (!is_object($nodeDB)) {
            return false;
        }

        $arStatus = [
            'server_id' => null,
        ];

        if ($bVariables) {
            $rs = $nodeDB->Query("show variables like 'server_id'", false, '', ['fixed_connection' => true]);
            if ($ar = $rs->Fetch()) {
                $arStatus['server_id'] = $ar['Value'];
            }
        }

        $rsSlaves = CClusterDBNode::GetList([], ['=MASTER_ID' => $node_id]);
        if ($rsSlaves->Fetch()) {
            $arStatus = array_merge($arStatus, [
                'File' => null,
                'Position' => null,
            ]);

            if ($bSlaveStatus) {
                $rs = $nodeDB->Query('SHOW MASTER STATUS', true, '', ['fixed_connection' => true]);
                if (!$rs) {
                    return GetMessage('CLU_NO_PRIVILEGES', ['#sql#' => "GRANT REPLICATION CLIENT on *.* to '".$nodeDB->DBLogin."'@'%';"]);
                }
                $ar = $rs->Fetch();
                if (is_array($ar)) {
                    foreach ($ar as $key => $value) {
                        if ('Last_Error' === $key) {
                            $key = 'Last_SQL_Error';
                        }
                        if (array_key_exists($key, $arStatus)) {
                            $arStatus[$key] = $value;
                        }
                    }
                }
            }
        }

        if (strlen($arNode['MASTER_ID'])) {
            $arStatus = array_merge($arStatus, [
                'Slave_IO_State' => null,
                'Slave_IO_Running' => null,
                'Read_Master_Log_Pos' => null,
                'Slave_SQL_Running' => null,
                'Exec_Master_Log_Pos' => null,
                'Seconds_Behind_Master' => null,
                'Last_IO_Error' => null,
                'Last_SQL_Error' => null,
                //			'Replicate_Ignore_Table' => null,
                'Com_select' => null,
            ]);

            if ($bSlaveStatus) {
                $rs = $nodeDB->Query('SHOW SLAVE STATUS', true, '', ['fixed_connection' => true]);
                if (!$rs) {
                    return GetMessage('CLU_NO_PRIVILEGES', ['#sql#' => "GRANT REPLICATION CLIENT on *.* to '".$nodeDB->DBLogin."'@'%';"]);
                }
                $ar = $rs->Fetch();
                if (is_array($ar)) {
                    foreach ($ar as $key => $value) {
                        if ('Last_Error' === $key) {
                            $key = 'Last_SQL_Error';
                        }
                        if (array_key_exists($key, $arStatus)) {
                            $arStatus[$key] = $value;
                        }
                    }
                }
            }
        }

        if ($bGlobalStatus) {
            $rs = $nodeDB->Query("show global status where Variable_name in ('Com_select', 'Com_do')", true, '', ['fixed_connection' => true]);
            if (is_object($rs)) {
                while ($ar = $rs->Fetch()) {
                    if ('Com_do' === $ar['Variable_name']) {
                        $arStatus['Com_select'] -= $ar['Value'] * 2;
                    } else {
                        $arStatus['Com_select'] += $ar['Value'];
                    }
                }
            } else {
                $rs = $nodeDB->Query("show status like 'Com_select'", false, '', ['fixed_connection' => true]);
                $ar = $rs->Fetch();
                if ($ar) {
                    $arStatus['Com_select'] += $ar['Value'];
                }
                $rs = $nodeDB->Query("show status like 'Com_do'", false, '', ['fixed_connection' => true]);
                $ar = $rs->Fetch();
                if ($ar) {
                    $arStatus['Com_select'] -= $ar['Value'] * 2;
                }
            }
        }

        return $arStatus;
    }

    public static function GetList()
    {
        global $DB, $CACHE_MANAGER;
        static $arSlaves = false;
        if (false === $arSlaves) {
            $cache_id = 'db_slaves_v2';
            if (
                CACHED_b_cluster_dbnode !== false
                && $CACHE_MANAGER->Read(CACHED_b_cluster_dbnode, $cache_id, 'b_cluster_dbnode')
            ) {
                $arSlaves = $CACHE_MANAGER->Get($cache_id);
            } else {
                $arSlaves = [];

                $rs = $DB->Query("
					SELECT ID, WEIGHT, ROLE_ID, GROUP_ID
					FROM b_cluster_dbnode
					WHERE STATUS = 'ONLINE' AND (SELECTABLE is null or SELECTABLE = 'Y')
					ORDER BY ID
				", false, '', ['fixed_connection' => true]);
                while ($ar = $rs->Fetch()) {
                    $arSlaves[(int) $ar['ID']] = $ar;
                }

                if (CACHED_b_cluster_dbnode !== false) {
                    $CACHE_MANAGER->Set($cache_id, $arSlaves);
                }
            }
        }

        return $arSlaves;
    }

    /**
     * @param array     $arNode
     * @param CDatabase $nodeDB
     */
    public static function AdjustServerID($arNode, $nodeDB)
    {
        $rs = $nodeDB->Query("show variables like 'server_id'", false, '', ['fixed_connection' => true]);
        if ($ar = $rs->Fetch()) {
            if ($ar['Value'] !== $arNode['SERVER_ID']) {
                $ob = new CClusterDBNode();
                $ob->Update($arNode['ID'], ['SERVER_ID' => $ar['Value']]);
            }
        }
    }

    public static function GetRandomNode()
    {
        $arSlaves = static::GetList();
        if (empty($arSlaves)) {
            return false;
        }

        $max_slave_delay = COption::GetOptionInt('cluster', 'max_slave_delay', 10);
        if (isset($_SESSION['BX_REDIRECT_TIME'])) {
            $redirect_delay = time() - $_SESSION['BX_REDIRECT_TIME'] + 1;
            if (
                $redirect_delay > 0
                && $redirect_delay < $max_slave_delay
            ) {
                $max_slave_delay = $redirect_delay;
            }
        }

        $total_weight = 0;
        foreach ($arSlaves as $i => $slave) {
            if (defined('BX_CLUSTER_GROUP') && BX_CLUSTER_GROUP !== $slave['GROUP_ID']) {
                unset($arSlaves[$i]);
            } elseif ('SLAVE' === $slave['ROLE_ID']) {
                $arSlaveStatus = static::GetStatus($slave['ID'], true, false, false);
                if (
                    $arSlaveStatus['Seconds_Behind_Master'] > $max_slave_delay
                    || '' !== $arSlaveStatus['Last_SQL_Error']
                    || '' !== $arSlaveStatus['Last_IO_Error']
                    || 'No' === $arSlaveStatus['Slave_SQL_Running']
                ) {
                    unset($arSlaves[$i]);
                } else {
                    $total_weight += $slave['WEIGHT'];
                    $arSlaves[$i]['PIE_WEIGHT'] = $total_weight;
                }
            } else {
                $total_weight += $slave['WEIGHT'];
                $arSlaves[$i]['PIE_WEIGHT'] = $total_weight;
            }
        }

        $found = false;
        $rand = mt_rand(0, $total_weight);
        foreach ($arSlaves as $slave) {
            if ($rand < $slave['PIE_WEIGHT']) {
                $found = $slave;

                break;
            }
        }

        if (!$found || 'SLAVE' !== $found['ROLE_ID']) {
            return false; // use main connection
        }

        return $found;
    }
}
