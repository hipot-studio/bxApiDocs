<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;

class User extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.User.list
	 */
	#[CloseSession]
	public function listAction(
		array $ids,
		UserRepositoryInterface $userRepository,
	): Entity\UserCollection
	{
		return $userRepository->getByIds($ids);
	}
}
