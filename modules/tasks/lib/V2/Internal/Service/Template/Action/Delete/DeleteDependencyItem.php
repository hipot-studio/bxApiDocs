<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Internals\Task\Template\DependenceTable;

class DeleteDependencyItem
{
	public function __invoke(array $template): void
	{
		// delete item itself
		$select = ['TEMPLATE_ID', 'PARENT_TEMPLATE_ID'];
		$filter = [
			'=TEMPLATE_ID' => $template['ID'],
			'=PARENT_TEMPLATE_ID' => $template['ID'],
		];

		$item = DependenceTable::getList(['select' => $select, 'filter' => $filter])->fetch();
		if ($item !== false)
		{
			DependenceTable::delete($item);
		}
	}
}
