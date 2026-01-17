<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

class PrepareMembers implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (
			isset($fields['ACCOMPLICES'])
			&& is_string($fields['ACCOMPLICES'])
		)
		{
			$fields['ACCOMPLICES'] = unserialize($fields['ACCOMPLICES'], ['allowed_classes' => false]);
		}

		if (
			isset($fields['AUDITORS'])
			&& is_string($fields['AUDITORS'])
		)
		{
			$fields['AUDITORS'] = unserialize($fields['AUDITORS'], ['allowed_classes' => false]);
		}

		return $fields;
	}
}
