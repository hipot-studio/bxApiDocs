<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Replication\Agent\AfterCompletionAgent;
use Bitrix\Tasks\Replication\Template\AfterCompletionChainStarter;
use Bitrix\Tasks\V2\Internal\DI\Container;

class EnableReplication
{
	public function __invoke(array $fields, bool $isReplicationEnabled): void
	{
		$replicator = Container::getInstance()->getRegularReplicator();

		$replicator->stopReplication($fields['ID']);
		AfterCompletionAgent::removeAll((int)$fields['ID']);

		if (!$isReplicationEnabled)
		{
			return;
		}

		$replicator->startReplicationAndUpdateTemplate($fields['ID'], $fields['REPLICATE_PARAMS']);

		// after_completion has no recurrent agent, so the call above arms nothing: (re)start the chain by
		// posting the first instance when the rule has no instance yet (PRD Q-INT-001). The starter's guards
		// keep this idempotent — an already-running chain (source task / produced instance / armed one-shot)
		// is not re-bootstrapped. A no-op for schedule / deadline.
		(new AfterCompletionChainStarter())->startIfNeeded((int)$fields['ID']);
	}
}
