<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

class PrepareMultitask implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (!is_array($fields['RESPONSIBLES'] ?? null))
		{
			return $fields;
		}

		if (count($fields['RESPONSIBLES']) > 1)
		{
			$fields['MULTITASK'] = 'Y';
		}
		else
		{
			$fields['MULTITASK'] = 'N';
		}

		return $fields;
	}
}
