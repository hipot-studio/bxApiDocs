<?php
declare(strict_types=1);

namespace Bitrix\ImMobile\Public\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Option;

final class LegacyFeatureFlagRegistry
{
	private const MODULE_ID = 'immobile';
	private const OPTIONS = [
		'beta_available' => [BetaAvailableFlag::class, 'N'],
		'chat_local_storage_available' => [ChatLocalStorageAvailableFlag::class, 'Y'],
		'is_market_available' => [MarketAvailableFlag::class, 'N'],
		'vibecode_recent_action_available' => [VibecodeRecentActionAvailableFlag::class, 'N'],
		'vibecode_app_chat_send_permission_available' => [VibecodeAppChatSendPermissionAvailableFlag::class, 'Y'],
		'is_markdown_native_renderer_available' => [MarkdownNativeRendererAvailableFlag::class, 'N'],
		'openlines_in_messenger_v2_available' => [OpenlinesInMessengerV2AvailableFlag::class, 'Y'],
	];

	/**
	 * @return array<string, class-string<AbstractFlag>>
	 */
	public static function getOptionToFlagMap(): array
	{
		return array_map(
			static fn (array $option): string => $option[0],
			self::OPTIONS,
		);
	}

	public static function isLegacyOptionEnabled(string $optionName): bool
	{
		if (!isset(self::OPTIONS[$optionName]))
		{
			return false;
		}

		$defaultOptionValue = self::OPTIONS[$optionName][1];

		return Option::get(self::MODULE_ID, $optionName, $defaultOptionValue) === 'Y';
	}

	public static function isLegacyOptionEnabledForFlag(string $flagClass): bool
	{
		foreach (self::OPTIONS as $optionName => $optionData)
		{
			$registeredFlagClass = $optionData[0];

			if ($registeredFlagClass === $flagClass)
			{
				return self::isLegacyOptionEnabled($optionName);
			}
		}

		return false;
	}
}
