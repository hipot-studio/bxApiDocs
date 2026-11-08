<?php

namespace Bitrix\Socialnetwork\Space\List\Query\Filter\Search;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\Space\List\Query\Filter\FilterInterface;
use Bitrix\Socialnetwork\WorkgroupTagTable;

final class SearchFilter implements FilterInterface
{
	public function __construct(private string $searchString)
	{}

	public function apply(Query $query): void
	{
		$searchStringPrepared = addcslashes($this->searchString, '%_');
		$tagSearchStringPrepared = addcslashes(
			WorkgroupTagTable::prepareNameForSearch($this->searchString),
			'%_',
		);

		$filter = Query::filter()
			->logic(ConditionTree::LOGIC_OR)
			->whereLike('NAME', "%$searchStringPrepared%")
		;

		if ($tagSearchStringPrepared !== '')
		{
			$filter->whereLike('TAG.NAME', "%$tagSearchStringPrepared%");
		}

		$query->where($filter);
		$query->setGroup('ID');
	}
}
