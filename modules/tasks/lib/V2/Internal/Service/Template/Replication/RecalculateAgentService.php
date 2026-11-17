<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Replication;

use Bitrix\Tasks\Replication\Replicator\RegularTemplateTaskReplicator;
use Bitrix\Tasks\Replication\Task\Regularity\Exception\RegularParameterException;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Consistency\Strategy\Transaction;

class RecalculateAgentService
{
	public function __construct(
		private readonly TemplateRepositoryInterface $templateRepository,
		private readonly RegularTemplateTaskReplicator $replicator,
		private readonly Transaction $transaction,
	)
	{
	}

	public function recalculate(int $templateId): void
	{
		$this->transaction->execute(fn () => $this->recalculateLocked($templateId));
	}

	private function recalculateLocked(int $templateId): void
	{
		$this->templateRepository->lockById($templateId);
		if (!$this->templateRepository->isReplicable($templateId))
		{
			return;
		}

		$params = $this->templateRepository->getReplicateParams($templateId);
		if ($params === null || $params === [])
		{
			throw new RegularParameterException('Template ' . $templateId . ' has no valid replicate parameters');
		}

		$this->replicator->startReplicationAndUpdateTemplate($templateId, $params);
	}
}
