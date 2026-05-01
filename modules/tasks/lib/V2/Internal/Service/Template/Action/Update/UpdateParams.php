<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\V2\Internal\DI\Container;

class UpdateParams
{
	public function __invoke(array $fields): void
	{
		if (empty($fields['ID']) || empty($fields['PARAMS']))
		{
			return;
		}

		Container::getInstance()
			->getTemplateParameterRepository()
			->updateLinks((int)$fields['ID'], $fields['PARAMS'])
		;
	}
}
