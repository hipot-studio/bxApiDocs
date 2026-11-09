<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Access;

use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Access\FieldAccessContext;
use Bitrix\Rest\V3\Access\FieldAccessProvider;

/**
 * Closes the field methods of the agent skill resources unless the builder family is open for the caller
 * and the BuilderAgentSkill flag is on.
 *
 * The field methods are served by the system controller of REST and never pass the entry point of
 * the builder controllers, so the flags are asked here, on every call.
 */
final class AgentSkillFieldAccessProvider implements FieldAccessProvider
{
	public const ERROR_NOT_PUBLISHED = 'BICONNECTOR_BUILDER_AGENT_SKILL_FIELDS_NOT_PUBLISHED';

	private readonly FlagGate $flagGate;

	public function __construct(?FlagGate $flagGate = null)
	{
		$this->flagGate = $flagGate ?? new FlagGate();
	}

	public function decide(FieldAccessContext $context): Result
	{
		if (
			(new BuilderFamilyGate($this->flagGate))->isEnabled($context->userId)
			&& $this->flagGate->isEnabled(FlagGate::BUILDER_AGENT_SKILL, $context->userId)
		)
		{
			return new Result();
		}

		return (new Result())->addError(new Error(
			"The field methods of '{$context->actionUri}' are not published.",
			self::ERROR_NOT_PUBLISHED,
		));
	}
}
