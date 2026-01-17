<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor;

use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Config\Option;

final class GlobalConfig
{
	use Singleton;

	public function getMaxVisibleChannels(): int
	{
		return (int)Option::get('crm', 'message_sender_editor_max_visible_channels', 10);
	}

	public function getMinVisibleChannels(): int
	{
		return (int)Option::get('crm', 'message_sender_editor_min_visible_channels', 1);
	}

	public function getRecommendedMaxMessageLength(): int
	{
		return (int)Option::get('crm', 'message_sender_editor_recommended_max_message_length', 200);
	}
}
