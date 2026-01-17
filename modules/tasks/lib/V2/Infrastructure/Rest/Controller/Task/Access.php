<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Controller\Task;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Tasks\V2\Infrastructure\Rest\Controller\ActionFilter\IsEnabledFilter;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\TaskDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Request\Task\Access\GetTaskAccessRequest;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;

#[DtoType(TaskDto::class)]
class Access extends RestController
{
	protected ?Context $context = null;

	protected int $userId;

	protected function init(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();
		$this->context = new Context($this->userId);

		parent::init();
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new IsEnabledFilter(),
		];
	}

	public function getAction(GetTaskAccessRequest $request, TaskRightService $taskRightService): ArrayResponse
	{
		$rights = $taskRightService->get(ActionDictionary::TASK_ACTIONS, $request->id, $this->userId);

		return new ArrayResponse($rights);
	}
}
