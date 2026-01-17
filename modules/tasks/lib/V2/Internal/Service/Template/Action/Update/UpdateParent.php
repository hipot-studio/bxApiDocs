<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Internals\DataBase\Tree\LinkExistsException;
use Bitrix\Tasks\Internals\Task\Template\DependenceTable;
use Bitrix\Tasks\V2\Internal\DI\Container;

class UpdateParent
{
	public function __invoke(array $fields): void
	{
		if (!isset($fields['BASE_TEMPLATE_ID']))
		{
			return;
		}

		try
		{
			DependenceTable::link($fields['ID'], (int)($fields['BASE_TEMPLATE_ID']));
		}
		catch(\Bitrix\Tasks\Internals\DataBase\Tree\LinkExistsException)
		{
		}
	}
}
