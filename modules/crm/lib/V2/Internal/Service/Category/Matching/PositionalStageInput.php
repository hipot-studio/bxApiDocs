<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Matching;

/**
 * One stage of a group replacement as the tool of the AI assistant sends it: a name and, if the model
 * chose one, a colour.
 *
 * There is no identifier here, and that is the whole contract rather than an omission - the schema of
 * the tool has none either
 * ({@see \Bitrix\Crm\Integration\AiAssistant\Tools\CategoryUpdateStagesList}), which is what leaves
 * {@see MatchByPosition} nothing to match a stage by but its place in the list. {@see StageInput}
 * would take an identifier and this strategy would ignore it, so the two contracts stay two types.
 *
 * A `null` colour is the absence of one rather than a value: the stage keeps the colour it has, and a
 * stage that has none is given the next colour of
 * {@see \Bitrix\Crm\Stage\DefaultColorGenerator}.
 *
 * @internal
 */
final readonly class PositionalStageInput
{
	public function __construct(
		public string $name,
		public ?string $color = null,
	)
	{
	}
}
