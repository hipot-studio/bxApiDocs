<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Internals\Task\Template\DependenceTable;

class SafeDelete
{
	public function __invoke(array $template): void
	{
		(new MarkTemplateDeleted())($template);

		(new RemoveAgents())($template);

		$parent = DependenceTable::getParentId($template['ID'])->fetch();
		$parent = $parent === false ? [] : $parent;
		$subTree = DependenceTable::getSubTree($template['ID'], [], ['INCLUDE_SELF' => false])->fetchAll();

		(new DeleteDependencies())($template);

		(new ReattachSubTemplates())($template, $parent, $subTree);

		(new DeleteDependencyItem())($template);
	}
}
