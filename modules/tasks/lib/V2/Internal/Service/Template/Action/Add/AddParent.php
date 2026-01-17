<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\Internals\DataBase\Tree\LinkExistsException;
use Bitrix\Tasks\Internals\Task\Template\DependenceTable;
use Bitrix\Tasks\V2\Internal\DI\Container;

class AddParent
{
	public function __invoke(array $fields): void
	{
		$baseId = (int)($fields['BASE_TEMPLATE_ID'] ?? null);
		if ($baseId <= 0)
		{
			return;
		}

		try
		{
			DependenceTable::createLink($fields['ID'], $baseId);
		}
		catch(LinkExistsException $e)
		{
			Container::getInstance()->getLogger()
				->logError($e);
		}
	}
}
