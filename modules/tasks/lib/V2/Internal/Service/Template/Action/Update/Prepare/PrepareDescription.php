<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Main\Text\Emoji;

class PrepareDescription implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		if (isset($fields['DESCRIPTION']))
		{
			$fields['DESCRIPTION'] = Emoji::encode(trim((string)$fields['DESCRIPTION']));
		}

		return $fields;
	}
}
