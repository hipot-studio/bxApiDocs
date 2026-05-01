<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\V2\Internal\DI\Container;

class AddParams
{
	public function __invoke(array $fields): void
	{
		if (empty($fields['ID']) || empty($fields['PARAMS']))
		{
			return;
		}

		Container::getInstance()
			->getTemplateParameterRepository()
			->link((int)$fields['ID'], $fields['PARAMS'])
		;
	}
}
