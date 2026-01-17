<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\Control\Exception\UserFieldTemplateAddException;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;

class CheckUserFields
{
	use UserFieldTrait;
	use ConfigTrait;

	public function __invoke(array $fields): void
	{
		if (!$this->checkContainsUfKeys($fields))
		{
			return;
		}

		if (!$this->checkFields(0, $fields, $this->config->getUserId(), UserField::TEMPLATE))
		{
			$message = $this->getApplicationError();

			throw new UserFieldTemplateAddException($message);
		}
	}
}
