<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;

class UpdateUserFields
{
	use ConfigTrait;
	use UserFieldTrait;

	public function __invoke(array $fields, int $taskId): bool
	{
		if ($this->checkContainsUfKeys($fields))
		{
			$result = $this->getUfManager()->Update(UserField::TASK, $taskId, $fields, $this->config->getUserId());

			Container::getInstance()->get(CrmItemRepositoryInterface::class)->invalidate($taskId);

			return $result;
		}

		return false;
	}
}
