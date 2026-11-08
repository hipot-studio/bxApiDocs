<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

/**
 * Single source of the "this is the template setup wizard node" predicate for the agent graph model.
 *
 * Deliberately kept outside the storage taxonomy of
 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\StorageNodeClassifier}: the wizard
 * configures the template as a whole and touches no storage, so answering for it there would invent a
 * commonality the two node families do not have. The wizard stays in that taxonomy's immutable list - what
 * this phase changes is its fate outside the baseline, not its immutability.
 *
 * The forward converter lower-cases every block type while an activity description declares its CLASS in
 * PascalCase, so the match is case-insensitive, mirroring {@see FrameBlockMatcher}.
 */
final class SetupTemplateNodeMatcher
{
	/** Lower-cased catalog type-code of the template setup wizard node. */
	public const SETUP_TEMPLATE_TYPE = 'setuptemplateactivity';

	public static function matches(mixed $type): bool
	{
		return is_string($type) && mb_strtolower($type) === self::SETUP_TEMPLATE_TYPE;
	}
}
