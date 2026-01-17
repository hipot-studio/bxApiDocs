<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Control\Exception\TemplateDeleteException;
use Bitrix\Tasks\Internals\Task\TemplateTable;

class DeleteTemplate
{
	public function __invoke(array $template): void
	{
		$result = TemplateTable::delete($template['ID']);
		if (!$result->isSuccess())
		{
			throw new TemplateDeleteException($result->getError()?->getMessage());
		}
	}
}
