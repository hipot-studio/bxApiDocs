<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Access\Permission\TasksTemplatePermissionTable;

class DeleteRights
{
	public function __invoke(array $template): void
	{
		$grants = TasksTemplatePermissionTable::getList(['filter' => ['=TEMPLATE_ID' => $template['ID']]])->fetchAll();
		foreach ($grants as $grant)
		{
			TasksTemplatePermissionTable::delete($grant['ID']);
		}
	}
}
