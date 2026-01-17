<?php

namespace Bitrix\Crm\MessageSender\UI;

use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\Provider\Wazzup;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Type;

final class Taxonomy
{
	private const WAZZUP_PERSONAL_CHANNEL_ID = 'wazzup';
	private const EDNA_WABA_CHANNEL_ID = 'ednaru';

	public static function isWazzupPersonal(Channel $channel): bool
	{
		return
			self::isSmsSender($channel)
			&& $channel->getId() === self::WAZZUP_PERSONAL_CHANNEL_ID
		;
	}

	public static function isWazzupWaba(Channel $channel): bool
	{
		return false;

		/*
		return
			self::isSmsSender($channel)
			&& $channel->getId() === 'todo_wazzup_waba_id'
		;
		*/
	}

	public static function isEdnaWaba(Channel $channel): bool
	{
		return
			self::isSmsSender($channel)
			&& $channel->getId() === 'ednaru';
	}

	public static function isTwilio(Channel $channel): bool
	{
		return
			self::isSmsSender($channel)
			&& ($channel->getId() === 'twilio' || $channel->getId() === 'twilio2');
	}

	public static function isSmsEdnaRu(Channel $channel): bool
	{
		return
			self::isSmsSender($channel)
			&& $channel->getId() === 'smsednaru';
	}

	public static function isSmsRu(Channel $channel): bool
	{
		return
			self::isSmsSender($channel)
			&& $channel->getId() === 'smsru';
	}

	public static function isSmsAssistent(Channel $channel): bool
	{
		return
			self::isSmsSender($channel)
			&& $channel->getId() === 'smsastby';
	}

	public static function isMobileMarketing(Channel $channel): bool
	{
		return
			self::isSmsSender($channel)
			&& $channel->getId() === 'smslineby';
	}

	public static function isRestSms(Channel $channel): bool
	{
		return
			self::isSmsSender($channel)
			&& $channel->getId() === 'rest'
		;
	}

	public static function isMassSms(Channel $channel): bool
	{
		// only waba for now
		return self::isEdnaWaba($channel);
	}

	public static function isSmsSender(Channel $channel): bool
	{
		return $channel->getSender()::getSenderCode() === SmsManager::getSenderCode();
	}

	public static function isNotifications(Channel $channel): bool
	{
		return $channel->getSender()::getSenderCode() === NotificationsManager::getSenderCode();
	}

	public static function isWhatsApp(Channel $channel, Channel\Correspondents\From $from): bool
	{
		return self::isWhatsAppByParams($channel->getSender()::getSenderCode(), $channel->getId(), $from->getType());
	}

	public static function isWhatsAppByParams(string $senderCode, string $channelId, ?string $fromType): bool
	{
		if ($senderCode === SmsManager::getSenderCode() && $channelId === self::EDNA_WABA_CHANNEL_ID)
		{
			return true;
		}

		if ($senderCode === SmsManager::getSenderCode() && $channelId === self::WAZZUP_PERSONAL_CHANNEL_ID)
		{
			return Type::tryFrom((string)$fromType) === Type::Whatsapp;
		}

		return false;
	}

	public static function isTelegram(Channel $channel, Channel\Correspondents\From $from): bool
	{
		return self::isTelegramByParams($channel->getSender()::getSenderCode(), $channel->getId(), $from->getType());
	}

	public static function isTelegramByParams(string $senderCode, string $channelId, ?string $fromType): bool
	{
		if ($senderCode === SmsManager::getSenderCode() && $channelId === self::WAZZUP_PERSONAL_CHANNEL_ID)
		{
			return (string)$fromType === Wazzup::TYPE_TGAPI || Type::tryFrom((string)$fromType) === Type::Telegram;
		}

		return false;
	}

	private function __construct()
	{
	}
}
