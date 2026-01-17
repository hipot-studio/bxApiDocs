<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;

class ChannelSettingsFactory
{
	private static array $channelClasses = [
		CallChannelSettings::CHANNEL_TYPE => CallChannelSettings::class,
		ChatChannelSettings::CHANNEL_TYPE => ChatChannelSettings::class,
	];

	public static function create(string $channelType, array $data): ?ChannelSettingsInterface
	{
		$className = self::$channelClasses[$channelType] ?? null;
		if ($className === null)
		{
			return null;
		}

		return $className::fromArray($data);
	}

	public static function createFromJson(array $jsonData): ?ChannelSettingsInterface
	{
		$channelType = $jsonData['channelType'] ?? null;

		// backwards compatibility for calls
		if (($channelType === null) && isset($jsonData['autostartCallDirections']))
		{
			$channelType = CallChannelSettings::CHANNEL_TYPE;
		}

		return self::create($channelType, $jsonData);
	}
}
