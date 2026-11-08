<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

final class ChangeAiSiteSelectedElementErrorMessages
{
	/**
	 * Prefix of the step 30 message that carries a selection reason. Owned here together with the
	 * texts themselves, so a reworded failure stays reworded everywhere it is shown.
	 */
	public const SELECTION_FAILURE_PREFIX = 'Failed to resolve selected element for update: ';

	private const DEBUG_REASON_NO_CONTEXT = 'no_context';
	private const DEBUG_REASON_BLOCK_NOT_FOUND = 'block_not_found';
	private const DEBUG_REASON_SELECTOR_MISS = 'selector_miss';
	private const DEBUG_DETAIL_FINGERPRINT_MISMATCH = 'fingerprint_mismatch';
	private const DEBUG_DETAIL_MATCH_COUNT_PATTERN = '/^match_count_(\d+)$/';
	private const REASON_TEXT_UNKNOWN = 'the selected element could not be resolved';

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
			30 => 'Failed to resolve selected element for update.',
			40 => 'Failed to prepare target blocks for page change.',
			50 => 'Failed to generate updated HTML for selected element.',
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

	/**
	 * User-facing wording of a failed selection of the element the user picked on the page.
	 * The step writes service codes into the selection debug ("selector_miss", "match_count_0"): they
	 * name the branch that failed, for the generation log, and mean nothing to the person reading the
	 * refusal. So the wording comes from a catalog keyed by those codes, and an unknown code answers
	 * with the neutral text rather than with the code itself, keeping the catalog closed by default.
	 */
	public static function formatSelectionReason(array $selectionDebug): string
	{
		$reason = trim((string)($selectionDebug['reason'] ?? ''));
		if ($reason === '')
		{
			return '';
		}

		return self::resolveReasonText($reason, trim((string)($selectionDebug['detail'] ?? '')));
	}

	private static function resolveReasonText(string $reason, string $detail): string
	{
		return match ($reason)
		{
			self::DEBUG_REASON_NO_CONTEXT => 'the element to change was not passed with the request',
			self::DEBUG_REASON_BLOCK_NOT_FOUND => 'the block of the selected element is no longer available on the page',
			self::DEBUG_REASON_SELECTOR_MISS => self::resolveSelectorMissText($detail),
			default => self::REASON_TEXT_UNKNOWN,
		};
	}

	/**
	 * The same reason splits into different answers by its detail: the element is gone, it has been
	 * edited since it was picked, or the selection is ambiguous. Only these forms are recognized;
	 * anything else falls back to the neutral wording of the reason.
	 */
	private static function resolveSelectorMissText(string $detail): string
	{
		if ($detail === self::DEBUG_DETAIL_FINGERPRINT_MISMATCH)
		{
			return 'the selected element has changed on the page since it was selected';
		}

		$matches = [];
		if (preg_match(self::DEBUG_DETAIL_MATCH_COUNT_PATTERN, $detail, $matches) === 1)
		{
			return (int)$matches[1] > 1
				? 'the selection matches several elements of the block at once'
				: 'the selected element was not found in the block'
			;
		}

		return 'the selected element could not be matched in the block';
	}

	private static function resolveSelectionMessage(array $selectionDebug): string
	{
		$mode = trim((string)($selectionDebug['mode'] ?? ''));
		$reason = self::formatSelectionReason($selectionDebug);
		if ($mode === 'error' && $reason !== '')
		{
			return self::SELECTION_FAILURE_PREFIX . $reason;
		}

		return self::getMap()[30];
	}
}
