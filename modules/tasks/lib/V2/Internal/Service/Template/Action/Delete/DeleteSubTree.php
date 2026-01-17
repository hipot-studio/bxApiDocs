<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Internals\DataBase\Tree\TargetNodeNotFoundException;
use Bitrix\Tasks\Internals\Task\Template\DependenceTable;

class DeleteSubTree
{
	public function __invoke(array $template): void
	{
		try
		{
			DependenceTable::deleteSubtree($template['ID']);
		}
		catch (TargetNodeNotFoundException $e)
		{
			// had no children, actually don't care
		}
	}
}
