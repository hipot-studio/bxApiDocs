<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\Bot;

use Bitrix\Im\Bot;

enum BotType: string
{
	case Bot = Bot::TYPE_BOT;
	case Network = Bot::TYPE_NETWORK;
	case Openline = Bot::TYPE_OPENLINE;
	case Supervisor = Bot::TYPE_SUPERVISOR;
	case Personal = Bot::TYPE_PERSONAL;

	public function toRestName(): string
	{
		return match ($this)
		{
			self::Bot => 'bot',
			self::Network => 'network',
			self::Openline => 'openline',
			self::Supervisor => 'supervisor',
			self::Personal => 'personal',
		};
	}

	public static function fromRestName(string $name): ?self
	{
		return match (mb_strtolower($name))
		{
			'bot' => self::Bot,
			'network' => self::Network,
			'openline' => self::Openline,
			'supervisor' => self::Supervisor,
			'personal' => self::Personal,
			default => null,
		};
	}

	public static function fromDbValue(string $dbValue): self
	{
		return self::tryFrom($dbValue) ?? self::Bot;
	}
}
