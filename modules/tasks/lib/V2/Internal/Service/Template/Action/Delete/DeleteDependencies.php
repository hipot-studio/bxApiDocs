<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Internals\DataBase\Tree\TargetNodeNotFoundException;
use Bitrix\Tasks\Internals\Task\Template\DependenceTable;

class DeleteDependencies
{
	public function __invoke(array $template): void
	{
		// delete link to parent
		try
		{
			DependenceTable::unlink($template['ID']);
		}
		catch (TargetNodeNotFoundException)
		{
			// it is okay :)
		}
	}
}
