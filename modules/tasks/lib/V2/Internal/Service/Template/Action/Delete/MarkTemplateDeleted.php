<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Main\Application;

class MarkTemplateDeleted
{
	public function __invoke(array $template): void
	{
		$connection = Application::getConnection();

		$connection->queryExecute('UPDATE b_tasks_template SET ZOMBIE = \'Y\' WHERE ID = ' . $template['ID']);
	}
}
