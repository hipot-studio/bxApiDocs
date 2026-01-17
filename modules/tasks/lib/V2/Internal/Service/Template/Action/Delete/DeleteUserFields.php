<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Delete;

use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;

class DeleteUserFields
{
	use UserFieldTrait;

	public function __invoke(array $template): void
	{
		$this->getUfManager()->Delete(UserField::TEMPLATE, $template['ID']);
	}
}
