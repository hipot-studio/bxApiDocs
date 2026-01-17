<?php

namespace Bitrix\Bizproc\Public\Provider\WorkflowTemplate;

use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;

class AiAgentProvider
{
	public function __construct(
		private readonly AiAgentRepository $aiAgentRepository,
	) {}

	/**
	 * @param list<int> $ids
	 *
	 * @return list<int>
	 */
	public function getOnlyExistAndAllowedToDeleteTemplateIds(
		array $ids,
		int $userIdDeleteBy,
		bool $isUserAdmin=false,
	): array
	{
		return $this->aiAgentRepository->getOnlyExistAndAllowedToDeleteTemplateIds($ids, $isUserAdmin, $userIdDeleteBy);
	}
}