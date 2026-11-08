<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Controller\Task\Gantt;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Tasks\V2\Infrastructure\Rest\Controller\ActionFilter\IsEnabledFilter;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping\Task\GanttLinkDtoMapper;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\GanttLinkDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Request\Task\Gantt\Link\ListRequest;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Read;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\GanttLinkRepositoryInterface;

#[DtoType(GanttLinkDto::class)]
class Link extends RestController
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

	public function getAutoWiredParameters(): array
	{
		return [
			...parent::getAutoWiredParameters(),
			new Parameter(
				GanttLinkRepositoryInterface::class,
				fn () => ServiceLocator::getInstance()->get(GanttLinkRepositoryInterface::class),
			),
		];
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidPaginationException
	 * @throws RequiredFieldInRequestException
	 * @throws SystemException
	 */
	public function listAction(
		ListRequest $request,
		GanttLinkRepositoryInterface $repository,
		GanttLinkDtoMapper $mapper,
	): ListResponse
	{
		$conditions = $request->filter?->getSimpleFilterConditions();
		if (!isset($conditions['taskId']) || (int)$conditions['taskId'] <= 0)
		{
			throw new RequiredFieldInRequestException('filter');
		}

		$taskId = (int)$conditions['taskId'];

		$entity = new Entity\Task(id: $taskId);
		if (!(new Read())->check($entity, $this->context))
		{
			throw new AccessDeniedException();
		}

		$pagination = $request->pagination ?? PaginationStructure::create([]);

		if ($pagination->getLimit() < 1)
		{
			throw new InvalidPaginationException(['limit' => $pagination->getLimit()]);
		}

		if ($pagination->getOffset() < 0)
		{
			throw new InvalidPaginationException(['offset' => $pagination->getOffset()]);
		}

		$collection = $repository->getTaskLinks($taskId, $pagination->getLimit(), $pagination->getOffset());

		return new ListResponse($mapper->mapToDtoCollection($collection, $request));
	}
}
