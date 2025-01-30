<?php

use Bitrix\Main\Application;

class controlleragent
{
    public static function CleanUp()
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();

        $connection->queryExecute('DELETE FROM b_controller_log WHERE TIMESTAMP_X < '.$helper->addDaysToDateTime(-14));
        $connection->queryExecute('DELETE FROM b_controller_task WHERE STATUS <> \'N\' AND DATE_EXECUTE IS NOT NULL AND DATE_EXECUTE < '.$helper->addDaysToDateTime(-14));
        $connection->queryExecute('DELETE FROM b_controller_command WHERE DATE_INSERT < '.$helper->addDaysToDateTime(-14));

        return 'CControllerAgent::CleanUp();';
    }

    public static function _OrderBy($arOrder, $arFields, $obUserFieldsSql = null)
    {
        $arOrderBy = [];
        if (is_array($arOrder)) {
            foreach ($arOrder as $by => $order) {
                $by = mb_strtoupper($by);
                $order = ('desc' === mb_strtolower($order) ? 'desc' : 'asc');

                if (
                    isset($arFields[$by], $arFields[$by]['FIELD_TYPE'])
                ) {
                    $arOrderBy[$by] = $arFields[$by]['FIELD_NAME'].' '.$order;
                } elseif (
                    isset($obUserFieldsSql)
                    && ($s = $obUserFieldsSql->GetOrder($by))
                ) {
                    $arOrderBy[$by] = $s.' '.$order;
                }
            }
        }

        if (count($arOrderBy)) {
            return 'ORDER BY '.implode(', ', $arOrderBy);
        }

        return '';
    }

    /**
     * @deprecated Use \Bitrix\Main\Application::getConnection()->lock()
     *
     * @param mixed $uniq
     */
    public static function _Lock($uniq)
    {
        $connection = Application::getConnection();

        return $connection->lock($uniq);
    }

    /**
     * @deprecated Use \Bitrix\Main\Application::getConnection()->unlock()
     *
     * @param mixed $uniq
     */
    public static function _UnLock($uniq)
    {
        $connection = Application::getConnection();

        return $connection->unlock($uniq);
    }
}
