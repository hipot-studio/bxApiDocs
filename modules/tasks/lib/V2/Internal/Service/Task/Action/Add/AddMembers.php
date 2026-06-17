<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Repository\TaskMemberRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\MemberTrait;

class AddMembers
{
	use ConfigTrait;
	use MemberTrait;

	public function __invoke(array $fields): void
	{
		$toSaveUserCollection = $this->makeMemberCollectionForSave($fields, null, null);

		if (!$toSaveUserCollection)
		{
			return;
		}

		$taskMemberRepository = Container::getInstance()->get(TaskMemberRepositoryInterface::class);
		$taskMemberRepository->saveMulti((int)$fields['ID'], $toSaveUserCollection);
	}
}
