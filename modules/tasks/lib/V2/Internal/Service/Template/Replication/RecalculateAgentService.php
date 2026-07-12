<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Replication;

use Bitrix\Tasks\Replication\Replicator\RegularTemplateTaskReplicator;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;

class RecalculateAgentService
{
	public function __construct(
		private readonly TemplateRepositoryInterface $templateRepository,
		private readonly RegularTemplateTaskReplicator $replicator,
	)
	{
	}

	public function recalculate(int $templateId): void
	{
		$params = $this->templateRepository->getReplicateParams($templateId);
		if ($params === null)
		{
			return;
		}

		$this->replicator->startReplicationAndUpdateTemplate($templateId, $params);
	}
}
