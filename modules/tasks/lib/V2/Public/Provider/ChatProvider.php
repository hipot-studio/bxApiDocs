<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Im;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;

class ChatProvider
{
	private readonly ChatRepositoryInterface $chatRepository;

	public function __construct()
	{
		$this->chatRepository = Container::getInstance()->get(ChatRepositoryInterface::class);
	}

	public function getByTaskId(int $taskId): ?Im\Entity\Chat
	{
		return $this->chatRepository->getByTaskId($taskId);
	}
}
