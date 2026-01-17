<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Exception\User\UserNotFoundException;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\TaskBuilder;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Trait\ConfigTrait;

class ConvertResponsibles implements ConvertFieldInterface
{
	use ConfigTrait;

	/**
	 * @throws UserNotFoundException
	 */
	public function __invoke(Template $template, TaskBuilder $taskBuilder): void
	{
		$responsibles = $template->responsibleCollection;

		if(!$responsibles || $responsibles->isEmpty())
		{
			return;
		}

		if($responsibles->count() === 1)
		{
			$taskBuilder->set('responsible', $responsibles->toArray()[0]);

			return;
		}

		$currentUserId = $this->config->userId;
		$currentUser = $responsibles->findOneById($currentUserId) ?? $this->getUserById($currentUserId);

		if (!$currentUser)
		{
			throw new UserNotFoundException('Current user not found');
		}

		$taskBuilder->set('responsible', $currentUser);
		$taskBuilder->set(
			'multiResponsibles',
			array_filter($responsibles->toArray(), static fn(array $user) => $user['id'] !== $currentUserId)
		);
	}

	private function getUserById(int $userId): ?Entity\User
	{
		$userRepository = Container::getInstance()->get(UserRepositoryInterface::class);

		return $userRepository->getByIds([$userId])->getFirstEntity();
	}
}
