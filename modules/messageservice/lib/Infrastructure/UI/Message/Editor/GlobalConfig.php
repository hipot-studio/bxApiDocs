<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\UI\Message\Editor;

use Bitrix\Main\Config\Option;

final class GlobalConfig
{
	public function getMaxVisibleChannels(): int
	{
		return (int)Option::get('messageservice', 'message_editor_max_visible_channels', 10);
	}

	public function getMinVisibleChannels(): int
	{
		return (int)Option::get('messageservice', 'message_editor_min_visible_channels', 1);
	}

	public function getRecommendedMaxMessageLength(): int
	{
		return (int)Option::get('messageservice', 'message_editor_recommended_max_message_length', 200);
	}
}
