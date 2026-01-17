<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Internals\Task\Template\DependenceTable;

class ReattachSubTemplates
{
	public function __invoke(array $template, array $parent, array $subTree): void
	{
		$parentId = (int)($parent['PARENT_TEMPLATE_ID'] ?? null);

		// reattach sub templates
		if ($parentId > 0)
		{
			foreach ($subTree as $element)
			{
				if ((int)$element['DIRECT'] === 1)
				{
					DependenceTable::moveLink($element['TEMPLATE_ID'], $parentId);
				}
			}

			return;
		}

		foreach ($subTree as $element)
		{
			if ((int)$element['DIRECT'] === 1)
			{
				DependenceTable::unlink($element['TEMPLATE_ID']);
			}
		}
	}
}
