<?php

IncludeModuleLangFile(__FILE__);

class blog_notify_schema
{
    public static function __construct() {}

    public static function OnGetNotifySchema()
    {
        $ar = [
            'post' => [
                'NAME' => GetMessage('BLG_NS_POST'),
                'PUSH' => 'Y',
            ],
            'post_mail' => [
                'NAME' => GetMessage('BLG_NS_POST_MAIL'),
                'PUSH' => 'Y',
            ],
            'comment' => [
                'NAME' => GetMessage('BLG_NS_COMMENT'),
                'PUSH' => 'N',
            ],
            'mention' => [
                'NAME' => GetMessage('BLG_NS_MENTION'),
                'PUSH' => 'N',
            ],
            'share' => [
                'NAME' => GetMessage('BLG_NS_SHARE'),
                'PUSH' => 'N',
            ],
            'share2users' => [
                'NAME' => GetMessage('BLG_NS_SHARE2USERS'),
                'PUSH' => 'Y',
            ],
        ];

        if (IsModuleInstalled('intranet')) {
            $ar['broadcast_post'] = [
                'NAME' => GetMessage('BLG_NS_BROADCAST_POST'),
                'SITE' => 'N',
                'MAIL' => 'Y',
                'XMPP' => 'N',
                'PUSH' => 'Y',
                'DISABLED' => [IM_NOTIFY_FEATURE_SITE, IM_NOTIFY_FEATURE_XMPP],
            ];
        }

        return [
            'blog' => [
                'NAME' => GetMessage('BLG_NS'),
                'NOTIFY' => $ar,
            ],
        ];
    }
}
