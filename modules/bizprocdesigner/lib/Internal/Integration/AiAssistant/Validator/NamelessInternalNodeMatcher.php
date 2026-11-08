<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

/**
 * Identity predicate for internal nodes whose system name is empty by design: an internal wiring point has no
 * catalog caption, yet a system AI agent's template.get emits it and the server must accept it back verbatim.
 * The block validator's fail-closed system-name guard exempts exactly these types, never "any empty name", so a
 * genuinely broken block still fails it.
 *
 * The forward converter canonicalises every block type to lower case, so the match is case-insensitive,
 * mirroring {@see FrameBlockMatcher}/{@see AgentStartTriggerMatcher}.
 */
final class NamelessInternalNodeMatcher
{
	/** Lower-cased catalog type-codes of internal nodes with an intentionally empty system name. */
	public const NAMELESS_INTERNAL_TYPES = [
		'aiassistantinternalmcpnode',
	];

	public static function matches(mixed $type): bool
	{
		return is_string($type)
			&& in_array(mb_strtolower($type), self::NAMELESS_INTERNAL_TYPES, true);
	}
}
