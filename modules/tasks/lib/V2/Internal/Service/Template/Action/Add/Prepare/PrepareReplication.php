<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

use Bitrix\Tasks\Replication\Template\Option\Options;
use Bitrix\Tasks\Util\Type;

class PrepareReplication implements PrepareFieldInterface
{
	public function __invoke(array $fields): array
	{
		if(isset($fields['TPARAM_REPLICATION_COUNT']))
		{
			$fields['TPARAM_REPLICATION_COUNT'] = (int) $fields['TPARAM_REPLICATION_COUNT'];
		}
		else
		{
			$fields['TPARAM_REPLICATION_COUNT'] = 0;
		}

		if (empty($fields['REPLICATE_PARAMS']))
		{
			$fields['REPLICATE_PARAMS'] = [];
		}

		if(
			is_string($fields['REPLICATE_PARAMS'])
			&& !empty($fields['REPLICATE_PARAMS'])
		)
		{
			$fields['REPLICATE_PARAMS'] = Type::unSerializeArray($fields['REPLICATE_PARAMS']);
		}

		$fields['REPLICATE_PARAMS'] = Options::validate($fields['REPLICATE_PARAMS']);

		return $fields;
	}
}
