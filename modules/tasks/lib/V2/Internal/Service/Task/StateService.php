<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Validation\Validator\JsonValidator;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\Deadline\Internals\Repository\DeadlineUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Entity\Task\State;
use CUserOptions;

class StateService
{
	public function __construct(
		private readonly DefaultDeadlineService $defaultDeadlineService,
		private readonly DeadlineUserOptionRepositoryInterface $deadlineUserOptionRepository,
	)
	{

	}

	public function set(State $state, int $userId): void
	{
		if ($state->defaultDeadline !== null)
		{
			$state->defaultDeadline->userId = $userId;
			$this->defaultDeadlineService->set($state->defaultDeadline);
		}

		$value = [];

		if ($state->matchesWorkTime !== null)
		{
			$value['matchesWorkTime'] = $this->castBoolValue($state->matchesWorkTime);
		}
		if ($state->needsControl !== null)
		{
			$value['needsControl'] = $this->castBoolValue($state->needsControl);
		}
		if ($state->defaultRequireResult !== null)
		{
			$value['defaultRequireResult'] = $this->castBoolValue($state->defaultRequireResult);
		}

		if (!empty($value))
		{
			CUserOptions::SetOption(
				category: 'tasks.v2',
				name: 'state.flags',
				value: Json::encode($value),
				user_id: $userId,
			);
		}
	}

	public function get(int $userId): ?State
	{
		$deadlineUserOption = $this->deadlineUserOptionRepository->getByUserId($userId);

		$data = [
			'defaultDeadline' => $deadlineUserOption,
			'userId' => $userId,
		];

		$state = CUserOptions::GetOption(
			category: 'tasks.v2',
			name: 'state.flags',
			default_value: null,
			user_id: $userId,
		);

		$validator = new JsonValidator();
		if ($validator->validate($state)->isSuccess())
		{
			$state = Json::decode($state);

			if (isset($state['matchesWorkTime']))
			{
				$data['matchesWorkTime'] = $state['matchesWorkTime'] === 'Y';
			}
			if (isset($state['needsControl']))
			{
				$data['needsControl'] = $state['needsControl'] === 'Y';
			}
			if (isset($state['defaultRequireResult']))
			{
				$data['defaultRequireResult'] = $state['defaultRequireResult'] === 'Y';
			}
		}

		return State::mapFromArray($data);
	}

	private function castBoolValue(bool $value): string
	{
		return $value === true ? 'Y' : 'N';
	}
}
