<?php

/**
 * Класс-контейнер событий модуля <b>controller</b>.
 */
class bx_events
{
    /**
     * после отключения управляемого сайта.
     *
     * <i>Вызывается в методе:</i><br>
     * CAllControllerMember::CloseMember
     */
    public static function OnAfterCloseMember() {}

    /**
     * перед обновлением счётчиков сайта. Позволяет модифицировать код обновляющего счётчика.
     *
     * <i>Вызывается в методе:</i><br>
     * CAllControllerMember::UpdateCounters
     */
    public static function OnBeforeUpdateCounters() {}

    /**
     * перед добавлением клиента.
     *
     * <i>Вызывается в методе:</i><br>
     * CControllerMember::CheckFields
     */
    public static function OnBeforeControllerMemberAdd() {}

    /**
     * перед обновлением клиента.
     *
     * <i>Вызывается в методе:</i><br>
     * CControllerMember::CheckFields
     */
    public static function OnBeforeControllerMemberUpdate() {}
}
