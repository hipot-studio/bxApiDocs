<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Tasks\V2\Internal\Access\Service\CheckListAccessService;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\CheckListItem;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Exception\CheckList\CheckListNotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\CreateCheckListDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\CreateCheckListItemDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\DeleteCheckListDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\DeleteCheckListItemDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\UpdateCheckListDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\UpdateCheckListItemDto;
use Bitrix\Tasks\V2\Internal\Repository\CheckListEntityRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\CheckList\NodeIdGenerator;

class CheckListService
{
	public function __construct(
		private readonly CheckListAccessService $accessService,
		private readonly \Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListService $checkListService,
		private readonly CheckListEntityRepositoryInterface $checkListEntityRepository,
		private readonly NodeIdGenerator $nodeIdGenerator,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 */
	public function add(CreateCheckListDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canAdd($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$checkList = [
			'title' => $dto->title,
			'nodeId' => $this->nodeIdGenerator->generate($dto->title . $dto->taskId),
		];

		$children = $this->prepareChildren($dto->checkListItems, $checkList);

		$newCheckLists = [$checkList, ...$children];

		$this->checkListService->add($newCheckLists, $dto->taskId, $userId);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws CheckListNotFoundException
	 * @throws InvalidIdentifierException
	 */
	public function update(UpdateCheckListDto|UpdateCheckListItemDto $dto, int $userId): void
	{
		if ($dto->getId() <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canUpdate($userId, $dto->getId()))
		{
			throw new AccessDeniedException();
		}

		$item = new CheckListItem(
			id: $dto->getId(),
			title: $dto->title,
			sortIndex: $dto->sortIndex,
		);

		$this->checkListService->updateItem($item, $userId);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 */
	public function delete(DeleteCheckListDto|DeleteCheckListItemDto $dto, int $userId): void
	{
		if ($dto->getId() <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canDelete($userId, $dto->getId()))
		{
			throw new AccessDeniedException();
		}

		$this->checkListService->delete($dto->getId(), $userId);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 */
	public function addItem(CreateCheckListItemDto $dto, int $userId): void
	{
		if ($dto->checkListId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		$taskId = $this->checkListEntityRepository->getIdByCheckListId($dto->checkListId, Type::Task);

		if (!$this->accessService->canAdd($userId, $taskId))
		{
			throw new AccessDeniedException();
		}

		$item = new CheckListItem(
			title: $dto->title,
			parentId: $dto->checkListId,
		);

		$this->checkListService->addItem($item, $userId);
	}

	protected function prepareChildren(array $childrenTitles, array $parent): array
	{
		$children = [];

		$sortIndex = 0;

		foreach ($childrenTitles as $key => $title)
		{
			$children[] = [
				'title' => $title,
				'nodeId' => $this->nodeIdGenerator->generate($title . $key . $parent['nodeId']),
				'parentNodeId' => $parent['nodeId'],
				'sortIndex' => $sortIndex++,
			];
		}

		return $children;
	}
}
