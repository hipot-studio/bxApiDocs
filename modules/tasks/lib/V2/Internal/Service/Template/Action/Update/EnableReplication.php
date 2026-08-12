<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\V2\Internal\DI\Container;

class EnableReplication
{
	public function __invoke(array $fields, bool $isReplicationEnabled): void
	{
		$replicator = Container::getInstance()->getRegularReplicator();

		if (!$isReplicationEnabled)
		{
			$replicator->stopReplication($fields['ID']);

			return;
		}

		$replicator->stopReplication($fields['ID']);
		$replicator->startReplicationAndUpdateTemplate($fields['ID'], $fields['REPLICATE_PARAMS']);
	}
}
