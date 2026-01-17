<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\Internals\SystemLogTable;

class DeleteSystemLog
{
	public function __invoke(array $template): void
	{
		SystemLogTable::deleteByFilter([
			'ENTITY_ID' => $template['ID'],
			'ENTITY_TYPE' => SystemLogTable::ENTITY_TYPE_TEMPLATE
		]);
	}
}
