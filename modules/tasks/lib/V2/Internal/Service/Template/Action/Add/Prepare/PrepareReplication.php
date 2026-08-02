<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

use Bitrix\Tasks\Replication\Template\Option\Options;
use Bitrix\Tasks\Util\Type;

class PrepareReplication implements PrepareFieldInterface
{
	public function __invoke(array $fields): array
	{
		if (empty($fields['REPLICATE_PARAMS']))
		{
			unset($fields['REPLICATE_PARAMS']);

			return $fields;
		}

		$fields['TPARAM_REPLICATION_COUNT'] = (int)($fields['TPARAM_REPLICATION_COUNT'] ?? 0);

		if(is_string($fields['REPLICATE_PARAMS']))
		{
			$fields['REPLICATE_PARAMS'] = Type::unSerializeArray($fields['REPLICATE_PARAMS']);
		}

		$hasDeadlineOffset = isset($fields['REPLICATE_PARAMS']['DEADLINE_OFFSET']);
		$isDeadlineAfterProvided = isset($fields['DEADLINE_AFTER']);

		if ($isDeadlineAfterProvided)
		{
			$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'] = (int)$fields['DEADLINE_AFTER'];
		}

		$fields['REPLICATE_PARAMS'] = Options::validate($fields['REPLICATE_PARAMS']);

		if (!$isDeadlineAfterProvided && $hasDeadlineOffset)
		{
			$fields['DEADLINE_AFTER'] = (int)$fields['REPLICATE_PARAMS']['DEADLINE_OFFSET'];
		}

		return $fields;
	}
}
