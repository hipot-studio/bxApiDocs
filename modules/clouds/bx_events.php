<?php

/**
 * Класс-контейнер событий модуля <b>clouds</b>.
 */
class bx_events
{
    /**
     * для подключения пользовательских облачных хранилищ.
     * <i>Вызывается в методе:</i><br>
     * CCloudStorage::_init<br><br>.
     *
     * @see http://dev.1c-bitrix.ru/api_help/clouds/events/index.php
     *
     * @author Bitrix
     */
    public static function OnGetStorageService() {}
}
