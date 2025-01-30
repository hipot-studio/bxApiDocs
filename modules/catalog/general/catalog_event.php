<?php

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class catalog_event
{
    public static function GetAuditTypes(): array
    {
        return [
            'CAT_YAND_AGENT' => '[CAT_YAND_AGENT] '.Loc::getMessage('CAT_YAND_AGENT'),
            'CAT_YAND_FILE' => '[CAT_YAND_FILE] '.Loc::getMessage('CAT_YAND_FILE'),
        ];
    }

    public static function GetYandexAgentEvent(): array
    {
        return ['CAT_YAND_AGENT', 'CAT_YAND_FILE'];
    }

    public static function GetYandexAgentFilter(): string
    {
        return '&find_audit_type[]=CAT_YAND_AGENT&find_audit_type[]=CAT_YAND_FILE';
    }
}
