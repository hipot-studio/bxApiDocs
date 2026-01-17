<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Relation;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;

class DeleteUserFields
{
	use UserFieldTrait;

	public function __invoke(array $fullTaskData): void
	{
		$this->getUfManager()->Delete(UserField::TASK, $fullTaskData['ID']);

		Container::getInstance()->get(CrmItemRepositoryInterface::class)->invalidate((int)$fullTaskData['ID']);
	}
}
