<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\V2\Internal\DI\Container;

class EnableReplication
{
	public function __invoke(array $fields): void
	{
		$replicator = Container::getInstance()->getRegularReplicator();

		$replicator->stopReplication($fields['ID']);

		if (
			!array_key_exists('REPLICATE', $fields)
			|| $fields['REPLICATE'] !== true
		)
		{
			return;
		}


		$replicator->startReplicationAndUpdateTemplate($fields['ID'], $fields['REPLICATE_PARAMS']);
	}
}
