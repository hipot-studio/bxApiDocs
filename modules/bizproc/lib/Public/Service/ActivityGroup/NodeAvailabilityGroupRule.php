<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\ActivityGroup;

use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\Public\Service\AiAgent\NodeAvailabilityServiceInterface;

/**
 * Hides an activity group from the designer catalog when AI agent nodes are not
 * available on the portal (e.g. restricted region or missing module).
 *
 * Reusable for any group gated by the same AI availability condition (AI, MCP, ...):
 * register one instance per group in bizproc/.settings.php.
 */
class NodeAvailabilityGroupRule implements HideableGroupRuleInterface
{
	public function __construct(
		private readonly ActivityGroup $group,
		private readonly NodeAvailabilityServiceInterface $nodeAvailabilityService,
	)
	{
	}

	public function getGroup(): ActivityGroup
	{
		return $this->group;
	}

	public function isHidden(): bool
	{
		return !$this->nodeAvailabilityService->isAvailable();
	}
}
