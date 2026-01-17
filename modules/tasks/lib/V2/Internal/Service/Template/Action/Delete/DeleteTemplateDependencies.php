<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Internals\Task\Template\TemplateDependenceTable;

class DeleteTemplateDependencies
{
	public function __invoke(array $template): void
	{
		TemplateDependenceTable::deleteByFilter(['=TEMPLATE_ID' => $template['ID']]);
	}
}
