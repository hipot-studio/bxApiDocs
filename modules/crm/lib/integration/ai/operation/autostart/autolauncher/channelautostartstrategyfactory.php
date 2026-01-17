<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\CallChannelSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChatChannelSettings;

final class ChannelAutoStartStrategyFactory
{
	public static function create(int $activityOperation, string $channelType, array $activityFields): ?BaseChannelAutoStartStrategy
	{
		return match ($channelType)
		{
			CallChannelSettings::CHANNEL_TYPE => new CallAutoStartStrategy($activityOperation, $activityFields),
			ChatChannelSettings::CHANNEL_TYPE => new ChatAutoStartStrategy($activityOperation, $activityFields),
			default => null,
		};
	}
}
