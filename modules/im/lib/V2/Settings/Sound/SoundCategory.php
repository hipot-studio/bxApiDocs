<?php

namespace Bitrix\Im\V2\Settings\Sound;

use Bitrix\Im\V2\Settings\Entity;

enum SoundCategory: string
{
	case General = 'general';
	case Messages = 'messages';
	case Notifications = 'notifications';
	case Calls = 'calls';
	case Service = 'service';

	public function settingName(): string
	{
		return match ($this)
		{
			self::General => Entity\General::SOUND_VOLUME_GENERAL,
			self::Messages => Entity\General::SOUND_VOLUME_MESSAGES,
			self::Notifications => Entity\General::SOUND_VOLUME_NOTIFICATIONS,
			self::Calls => Entity\General::SOUND_VOLUME_CALLS,
			self::Service => Entity\General::SOUND_VOLUME_SERVICE,
		};
	}
}
