<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

/**
 * @internal
 */
final readonly class WritePlan
{
	/**
	 * @param non-empty-list<string>|null $requiredStageIds
	 */
	public function __construct(
		private LegacyRequirementRule $rule,
		private ?array $requiredStageIds,
	)
	{
	}

	public function getRule(): LegacyRequirementRule
	{
		return $this->rule;
	}

	/**
	 * @return non-empty-list<string>|null
	 */
	public function getRequiredStageIds(): ?array
	{
		return $this->requiredStageIds;
	}
}
