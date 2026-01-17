<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

class PrepareBBCodes implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (!isset($fields['DESCRIPTION_IN_BBCODE']))
		{
			return $fields;
		}

		$fields['DESCRIPTION_IN_BBCODE'] = 'Y';

		return $fields;
	}
}
