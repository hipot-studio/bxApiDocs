<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Services;

use Bitrix\AI\Services\CopilotNameService;
use Bitrix\Main\Loader;

/**
 * Service for retrieving and processing the Copilot assistant's name.
 */
class NameService
{
	/**
	 * Retrieves the configured name of the Copilot assistant.
	 *
	 * @return string|null The name of the Copilot assistant if available, or null if the AI module is not loaded.
	 */
	public static function getCopilotName(): ?string
	{
		if (!Loader::includeModule('ai'))
		{
			return null;
		}

		return (new CopilotNameService())->getCopilotName();
	}

	/**
	 * Replaces the placeholder #COPILOT_NAME# in a given phrase with the actual Copilot name.
	 *
	 * @param string|null $phrase The input text containing the #COPILOT_NAME# placeholder.
	 *
	 * @return string The processed string with the placeholder replaced by the Copilot name or an empty string.
	 */
	public static function replaceCopilotName(?string $phrase): string
	{
		if (!is_string($phrase))
		{
			return '';
		}

		$copilotName = self::getCopilotName();

		if (!is_string($copilotName))
		{
			return $phrase;
		}

		return str_replace("#COPILOT_NAME#", $copilotName, $phrase);
	}
}