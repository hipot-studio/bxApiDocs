<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Prepare;

class PrepareBBCodes implements PrepareFieldInterface
{
	public function __invoke(array $fields): array
	{
		$fields['DESCRIPTION_IN_BBCODE'] = 'Y'; // todo

		return $fields;
	}
}
