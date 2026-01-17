<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\V2\Internal\DI\Container;

class EnableReplication
{
	public function __invoke(array $fields): void
	{
		if (
			!array_key_exists('REPLICATE', $fields)
			|| $fields['REPLICATE'] !== true
		)
		{
			return;
		}

		$replicator = Container::getInstance()->getRegularReplicator();

		$replicator->startReplicationAndUpdateTemplate($fields['ID'], $fields['REPLICATE_PARAMS']);
	}
}
