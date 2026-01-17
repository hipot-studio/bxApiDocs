<?php

namespace Bitrix\Tasks\Internals\Notification\Strategy\Default;

use Bitrix\Tasks\Internals\Notification\Dictionary;
use Bitrix\Tasks\Internals\Notification\Strategy\RecipientStrategyInterface;
use Bitrix\Tasks\Internals\Notification\User;
use Bitrix\Tasks\Internals\Notification\UserRepositoryInterface;
use Bitrix\Tasks\Internals\TaskObject;

class TaskUpdatedV2Strategy implements RecipientStrategyInterface
{
	use StrategyConstructorTrait;

	private UserRepositoryInterface $userRepository;
	private TaskObject $task;
	private Dictionary $dictionary;

	public function getRecipients(): array
	{
		$recipients = $this->dictionary->get('recipients') ?? null;

		if (!$recipients)
		{
			return [];
		}

		return $this->userRepository->getUsersByIds($recipients);
	}

	public function getSender(): ?User
	{
		return $this->userRepository->getSender($this->task, $this->dictionary->get('options', []));
	}
}
