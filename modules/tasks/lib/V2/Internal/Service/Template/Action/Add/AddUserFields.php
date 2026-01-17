<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add;

use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;

class AddUserFields
{
	use UserFieldTrait;

	public function __construct(
		private readonly AddConfig $config
	)
	{

	}

	public function __invoke(array $fields): void
	{
		$this->getUfManager()->Update(UserField::TEMPLATE, $fields['ID'], $fields, $this->config->getUserId());
	}
}
