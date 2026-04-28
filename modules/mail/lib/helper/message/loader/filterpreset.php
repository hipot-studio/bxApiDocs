<?php

namespace Bitrix\Mail\Helper\Message\Loader;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\UI\Filter\Options;

class FilterPreset
{
	/**
	 * @throws ArgumentNullException
	 */
	public static function getAllPresets(int $mailboxId): array
	{
		$presets = self::getUiFilterOptions($mailboxId)->getPresets();

		return array_map(
			static fn($key) => [
				'id' => $key,
				'name' => $presets[$key]['name'],
				'fields' => ($presets[$key]['preparedFields'] ?? []),
				'default' => (bool)$presets[$key]['default'],
			],
			array_keys($presets)
		);
	}

	/**
	 * @throws ArgumentNullException
	 */
	public static function getFilterByPresetId(string $presetId, int $mailboxId): ?array
	{
		return self::getUiFilterOptions($mailboxId)->getFilterSettings($presetId);
	}

	/**
	 * @throws ArgumentNullException
	 */
	private static function getUiFilterOptions(int $mailboxId): Options
	{
		return new Options(self::getFilterId($mailboxId));
	}

	private static function getFilterId(int $mailboxId): string
	{
		return 'mail-message-list-' . $mailboxId;
	}
}