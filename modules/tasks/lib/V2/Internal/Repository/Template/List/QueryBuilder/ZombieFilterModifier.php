<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Query\Query;

class ZombieFilterModifier extends BaseFilterModifier
{
	public function modify(Query $query): Query
	{
		$value = $this->value;

		if (is_bool($value))
		{
			$value = $value ? 'Y' : 'N';
		}

		return $query->where($this->field->value, $this->operator, $value);
	}
}