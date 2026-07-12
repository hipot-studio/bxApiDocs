<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Access;

use Bitrix\Im\V2\Chat\Type\TypeRegistry;

final class SingleLevelAccessFilterFactory
{
	public function __construct(
		private readonly OpenChatAccessPolicy $policy,
		private readonly TypeRegistry $typeRegistry,
	) {}

	public function forUser(
		int $userId,
		string $relationAlias = 'RELATION',
		?string $chatAlias = null,
	): SingleLevelAccessFilter
	{
		$openCondition = null;

		if ($this->policy->canSkipMembershipForOpenChats($userId))
		{
			$condition = $this->typeRegistry->getOpenTypeCondition();
			$openCondition = $condition->hasConditions() ? $condition : null;
		}

		return new SingleLevelAccessFilter($openCondition, $relationAlias, $chatAlias);
	}
}
