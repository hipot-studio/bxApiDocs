<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;

class UpdateUserFields
{
	use UserFieldTrait;

	public function __construct(
		private readonly UpdateConfig $config
	)
	{

	}

	public function __invoke(array $fields): bool
	{
		if ($this->checkContainsUfKeys($fields))
		{
			return $this->getUfManager()->Update(UserField::TEMPLATE, $fields['ID'], $fields, $this->config->getUserId());
		}

		return false;
	}
}
