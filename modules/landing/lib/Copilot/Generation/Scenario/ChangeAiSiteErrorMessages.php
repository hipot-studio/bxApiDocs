<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

final class ChangeAiSiteErrorMessages
{
	private function __construct()
	{
	}

	/**
	 * @return array<int, string>
	 */
	public static function getMap(): array
	{
		return [
			10 => 'Failed to initialize page change context.',
			20 => 'Failed to collect page style context.',
			25 => 'The requested change is not allowed for AI site generation.',
			30 => 'Failed to select blocks for update.',
			40 => 'Failed to prepare target blocks for page change.',
			50 => 'Failed to generate updated HTML for target blocks.',
			60 => 'Failed to improve generated HTML for target blocks.',
			70 => 'Failed to prepare generated HTML markup for target blocks.',
			80 => 'Failed to prepare CRM forms in generated HTML.',
			90 => 'Failed to save source block history.',
			100 => 'Failed to enable Tailwind CSS runtime rebuild mode.',
			110 => 'Failed to apply generated HTML to target blocks.',
			120 => 'Failed to sync fonts for updated blocks.',
			140 => 'Failed to generate images for updated blocks.',
			150 => 'Failed to save block history changes.',
		];
	}

	public static function resolve(int $stepId, array $selectionDebug = []): string
	{
		if ($stepId === 30)
		{
			return self::resolveSelectionMessage($selectionDebug);
		}

		return self::getMap()[$stepId] ?? '';
	}

	private static function resolveSelectionMessage(array $selectionDebug): string
	{
		$mode = trim((string)($selectionDebug['mode'] ?? ''));
		$reason = trim((string)($selectionDebug['reason'] ?? ''));
		if ($mode === 'error' && $reason !== '')
		{
			return 'Failed to select blocks for update: ' . $reason;
		}

		return self::getMap()[30];
	}
}
