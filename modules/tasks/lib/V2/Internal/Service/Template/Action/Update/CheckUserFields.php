<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Control\Exception\UserFieldTemplateAddException;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;

class CheckUserFields
{
	use UserFieldTrait;
	use ConfigTrait;

	public function __invoke(array $fields, array $fullTemplateData): void
	{
		if (!$this->checkContainsUfKeys($fields))
		{
			return;
		}

		if (!$this->checkFields($fullTemplateData['ID'], $fields, $this->config->getUserId(), UserField::TEMPLATE))
		{
			$message = $this->getApplicationError();

			throw new UserFieldTemplateAddException($message);
		}
	}
}
