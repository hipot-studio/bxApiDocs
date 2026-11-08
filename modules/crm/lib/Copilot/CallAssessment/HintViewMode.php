<?php

namespace Bitrix\Crm\Copilot\CallAssessment;

final class HintViewMode
{
	public const CALL_OPTION = 'copilot_call_hint_call';

	private const ALLOWED_MODES = ['hint', 'rules'];

	public static function getForUser(string $optionName, int $userId): ?string
	{
		if ($userId <= 0 || $optionName !== self::CALL_OPTION)
		{
			return null;
		}

		$option = \CUserOptions::GetOption('crm', $optionName, [], $userId);
		$mode = is_array($option) ? ($option['mode'] ?? null) : null;

		return is_string($mode) && in_array($mode, self::ALLOWED_MODES, true) ? $mode : null;
	}
}
