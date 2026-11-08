<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;

/**
 * Identity predicate for the agent entry nodes whose settings the reverse path carries through verbatim.
 *
 * Both types declare an empty settings schema by design, while the old-format template converter writes
 * presentational settings (Icon, ColorIndex) onto every trigger. Those names are not bounded from above, so
 * the ordinary settings check would refuse them and break the round-trip of templates that carry them.
 *
 * The forward converter lower-cases every block type while an activity description declares its CLASS in
 * PascalCase, so the match is case-insensitive, mirroring {@see FrameBlockMatcher}.
 */
final class AgentStartTriggerMatcher
{
	/** Lower-cased catalog type-codes of the entry nodes carried through verbatim. */
	public const VERBATIM_START_TRIGGER_TYPES = [
		'aiagentstarttrigger',
		'aiagentrestarttrigger',
	];

	public static function matches(mixed $type): bool
	{
		return is_string($type)
			&& in_array(mb_strtolower($type), self::VERBATIM_START_TRIGGER_TYPES, true);
	}

	/**
	 * The entry node has no typed settings contract, so all fields which reach the write path identify its published
	 * instance. Keep the setting values verbatim: normalising an otherwise opaque setting would turn a changed node
	 * into a baseline match.
	 */
	public static function referenceFingerprint(AgentBlock $block): string
	{
		$settings = [];
		foreach ($block->settings as $setting)
		{
			$settings[] = [$setting->name, $setting->value];
		}

		return hash('sha256', serialize([
			'type' => mb_strtolower($block->type),
			'id' => $block->id,
			'title' => $block->title,
			'description' => $block->description,
			'settings' => $settings,
		]));
	}
}
