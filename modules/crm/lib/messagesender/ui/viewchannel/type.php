<?php

namespace Bitrix\Crm\MessageSender\UI\ViewChannel;

/**
 * Type of channel for UI purposes. Telegram, Whatsapp and Sms have specific icons, texts and stuff.
 * Other types represented as generic messages.
 */
enum Type: string
{
	case Telegram = 'telegram';
	case Whatsapp = 'whatsapp';
	case Sms = 'sms';
	case Generic = 'generic';
}
