<?php

IncludeModuleLangFile(__FILE__);

class queue
{
    public static function Add($group_id, $command, $param1, $param2, $param3)
    {
        global $DB;

        $sql_group_id = "'".(int) $group_id."'";
        $sql_command = "'".$DB->ForSQL($command, 50)."'";
        $sql_param1 = CClusterQueue::QuoteParam($param1);
        $sql_param2 = CClusterQueue::QuoteParam($param2);
        $sql_param3 = CClusterQueue::QuoteParam($param3);

        $DB->Query('
			INSERT INTO b_cluster_queue (
			TIMESTAMP_X, GROUP_ID, COMMAND, PARAM1, PARAM2, PARAM3
			) values (
			'.$DB->CurrentTimeFunction().', '.$sql_group_id.', '.$sql_command.', '.$sql_param1.', '.$sql_param2.', '.$sql_param3.'
			)
		');
    }

    public static function QuoteParam($str)
    {
        global $DB;

        if (is_bool($str)) {
            return "'b:".(true === $str ? 't' : 'f')."'";
        }
        if (is_string($str)) {
            return "'s:".$DB->ForSQL($str, 250)."'";
        }

        return 'null';
    }

    public static function UnQuoteParam($str)
    {
        if ('' !== $str) {
            $prefix = substr($str, 0, 2);
            if ('s:' === $prefix) {
                return substr($str, 2);
            }
            if ('b:' === $prefix) {
                return 't' === substr($str, 2);
            }
        }

        return null;
    }

    public static function Run()
    {
        global $DB;

        $rs = $DB->Query('
			SELECT *
			FROM b_cluster_queue
			WHERE GROUP_ID = '.BX_CLUSTER_GROUP.'
			ORDER BY ID
		');
        while ($ar = $rs->Fetch()) {
            $class_name = $ar['COMMAND'];
            if (class_exists($class_name)) {
                $object = new $class_name();
                $object->QueueRun(
                    CClusterQueue::UnQuoteParam($ar['PARAM1']),
                    CClusterQueue::UnQuoteParam($ar['PARAM2']),
                    CClusterQueue::UnQuoteParam($ar['PARAM3'])
                );
            }
            $DB->Query('DELETE FROM b_cluster_queue WHERE ID = '.(int) $ar['ID']);
        }
    }
}
