<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Main\Localization\Loc;

/** Source of every item the row menu and the mass action panel are built from. */
final class GridActionsCatalog
{
	public static function getAll(): array
	{
		return [
			'view' => [
				'id' => 'view',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_open_mail.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_VIEW'),
				'title' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_VIEW'),
			],
			'delete' => [
				'id' => 'delete',
				'icon' => '/bitrix/images/mail/mailservice-icon/mail-actionpanel_remove.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_DELETE'),
				'title' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_DELETE'),
			],
			'scheduledSendNow' => [
				'id' => 'scheduledSendNow',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_move.svg',
				'text' => Loc::getMessage('MAIL_SCHEDULED_LIST_SEND_NOW_ACTION'),
				'title' => Loc::getMessage('MAIL_SCHEDULED_LIST_SEND_NOW_ACTION_TITLE'),
			],
			'scheduledEdit' => [
				'id' => 'scheduledEdit',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_edit.svg',
				'text' => Loc::getMessage('MAIL_SCHEDULED_LIST_EDIT_ACTION'),
				'title' => Loc::getMessage('MAIL_SCHEDULED_LIST_EDIT_ACTION_TITLE'),
			],
			// cancelling a scheduled send keeps the letter as a draft, so it is neither named nor drawn as a deletion
			'scheduledCancel' => [
				'id' => 'scheduledCancel',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_recover.svg',
				'text' => Loc::getMessage('MAIL_SCHEDULED_LIST_CANCEL_ACTION'),
				'title' => Loc::getMessage('MAIL_SCHEDULED_LIST_CANCEL_ACTION'),
			],
			'scheduledRetry' => [
				'id' => 'scheduledRetry',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_history.svg',
				'text' => Loc::getMessage('MAIL_SCHEDULED_LIST_RETRY_ACTION'),
				'title' => Loc::getMessage('MAIL_SCHEDULED_LIST_RETRY_ACTION'),
			],
			'deleteImmediately' => [
				'id' => 'deleteImmediately',
				'icon' => '/bitrix/images/mail/mailservice-icon/mail-actionpanel_remove.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_DELETE_IMMEDIATELY'),
				'title' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_DELETE_IMMEDIATELY'),
			],
			'spam' => [
				'id' => 'spam',
				'icon' => '/bitrix/images/mail/mailservice-icon/mail-actionpanel_lock.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_SPAM'),
				'title' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_SPAM'),
			],
			'notSpam' => [
				'id' => 'notSpam',
				'icon' => '/bitrix/images/mail/mailservice-icon/mail-actionpanel_not_spam.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_NOT_SPAM'),
				'title' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_NOT_SPAM'),
			],
			'addToCrm' => [
				'id' => 'addToCrm',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_save_to_crm.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_CREATE_CRM_BTN'),
				'title' => Loc::getMessage('MAIL_MESSAGE_CREATE_CRM_BTN_TITLE'),
			],
			'excludeFromCrm' => [
				'id' => 'excludeFromCrm',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_exclude.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_CREATE_CRM_EXCLUDE_BTN'),
				'title' => Loc::getMessage('MAIL_MESSAGE_CREATE_CRM_EXCLUDE_BTN'),
			],
			'task' => [
				'id' => 'task',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_create.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_CREATE_TASK_BTN'),
				'title' => Loc::getMessage('MAIL_MESSAGE_CREATE_TASK_BTN_TITLE'),
			],
			'event' => [
				'id' => 'event',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_event.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_CREATE_EVENT_BTN'),
				'title' => Loc::getMessage('MAIL_MESSAGE_CREATE_EVENT_BTN_TITLE'),
			],
			'liveFeed' => [
				'id' => 'liveFeed',
				'text' => Loc::getMessage('MAIL_MESSAGE_CREATE_FEED_POST'),
				'title' => Loc::getMessage('MAIL_MESSAGE_CREATE_FEED_POST_TITLE'),
			],
			'discuss' => [
				'id' => 'discuss',
				'icon' => '/bitrix/js/ui/actionpanel/images/ui_icon_actionpanel_discuss_in_chat.svg',
				'text' => Loc::getMessage('MAIL_PANEL_DISCUSS_BTN'),
				'title' => Loc::getMessage('MAIL_PANEL_DISCUSS_BTN_TITLE'),
			],
			'chat' => [
				'id' => 'chat',
				'text' => Loc::getMessage('MAIL_MESSAGE_CREATE_IM_BTN'),
				'title' => Loc::getMessage('MAIL_MESSAGE_CREATE_IM_BTN_TITLE'),
			],
			'discussInChat' => [
				'id' => 'discussInChat',
				'text' => Loc::getMessage('MAIL_MESSAGE_DISCUSS_IN_CHAT_BTN'),
				'title' => Loc::getMessage('MAIL_MESSAGE_DISCUSS_IN_CHAT_BTN_TITLE'),
			],
			'read' => [
				'id' => 'read',
				'icon' => '/bitrix/images/mail/mailservice-icon/mail-open-envelope.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_SEEN'),
				'title' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_SEEN_TITLE'),
			],
			'notRead' => [
				'id' => 'notRead',
				'icon' => '/bitrix/images/mail/mailservice-icon/mail-closed-envelope.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_UNSEEN'),
				'title' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_UNSEEN_TITLE'),
			],
			'move' => [
				'id' => ':move:',
				'icon' => '/bitrix/images/mail/mailservice-icon/mail-actionpanel_move_to_folder.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_MOVE'),
				'title' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_MOVE'),
			],
			'assignLabel' => [
				'id' => 'assignLabel',
				'icon' => '/bitrix/images/mail/mailservice-icon/mail-actionpanel_label.svg',
				'text' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_ASSIGN_LABEL'),
				'title' => Loc::getMessage('MAIL_MESSAGE_LIST_BTN_ASSIGN_LABEL'),
			],
		];
	}

	/** A submenu whose content arrives on hover holds this item until it does. */
	public static function getLoadingItem(): array
	{
		return [
			'id' => 'loading',
			'text' => Loc::getMessage('MAIL_CLIENT_BUTTON_LOADING'),
			'disabled' => true,
			'items' => [],
		];
	}
}
