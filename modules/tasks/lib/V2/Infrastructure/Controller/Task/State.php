<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task;

use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Deadline\Service\DefaultDeadlineStateProvider;
use Bitrix\Tasks\Deadline\Service\DefaultDeadlineValueService;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Infrastructure\Controller\Task\Dto\DefaultDeadlineResponse;
use Bitrix\Tasks\V2\Infrastructure\Controller\Task\Dto\DefaultDeadlineValueDto;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Command\Task\State\SetStateCommand;

class State extends BaseController
{
	public function configureActions(): array
	{
		return [
			'getDefaultDeadline' => [
				'+prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])],
			],
			'setDefaultDeadlineValue' => [
				'+prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])],
			],
		];
	}

	/**
	 * @ajaxAction tasks.V2.Task.State.getDefaultDeadline
	 */
	public function getDefaultDeadlineAction(
		DefaultDeadlineStateProvider $defaultDeadlineStateProvider,
	): DefaultDeadlineResponse
	{
		$defaultDeadline = $defaultDeadlineStateProvider->getDefaultDeadline(
			userId: $this->userId,
			now: new DateTime(),
		);

		return new DefaultDeadlineResponse($defaultDeadline);
	}

	/**
	 * @ajaxAction tasks.V2.Task.State.setDefaultDeadlineValue
	 */
	public function setDefaultDeadlineValueAction(
		int $defaultDeadlineInSeconds,
		bool $isExactDeadlineTime,
		DefaultDeadlineValueService $defaultDeadlineValueService,
	): DefaultDeadlineResponse
	{
		$defaultDeadlineValue = DefaultDeadlineValueDto::createFromArray([
			'defaultDeadlineInSeconds' => $defaultDeadlineInSeconds,
			'isExactDeadlineTime' => $isExactDeadlineTime,
		]);
		$defaultDeadlineValue->validate();

		$defaultDeadline = $defaultDeadlineValueService->set(
			userId: $this->userId,
			defaultDeadlineInSeconds: $defaultDeadlineValue->defaultDeadlineInSeconds,
			isExactDeadlineTime: $defaultDeadlineValue->isExactDeadlineTime,
			now: new DateTime(),
		);

		return new DefaultDeadlineResponse($defaultDeadline);
	}

	/**
	 * @ajaxAction tasks.V2.Task.State.set
	 */
	public function setAction(
		Entity\Task\State $state,
	): array|bool|null
	{
		$result = (new SetStateCommand(
			state: $state,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getDataByKey('deadlineUserOption') ?? true;
	}
}
