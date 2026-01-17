<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Tasks\Internals\Task\Priority;

class PreparePriority implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (
			isset($fields['PRIORITY'])
			&& !in_array((int)$fields['PRIORITY'], array_values(Priority::getAll()), true)
		)
		{
			$fields['PRIORITY'] = Priority::AVERAGE;
		}

		return $fields;
	}
}
