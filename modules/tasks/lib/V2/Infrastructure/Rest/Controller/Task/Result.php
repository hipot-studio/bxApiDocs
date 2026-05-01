<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Controller\Task;

use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Controller\ValidateDtoTrait;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Tasks\V2\Infrastructure\Rest\Controller\ActionFilter\IsEnabledFilter;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Mapping\Task\ResultDtoMapper;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task\ResultDto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Request\Task\Result\ListRequest;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Read;
use Bitrix\Tasks\V2\Internal\Access\Task\Result\Permission\Add;
use Bitrix\Tasks\V2\Internal\Access\Task\Result\Permission\Delete;
use Bitrix\Tasks\V2\Internal\Access\Task\Result\Permission\Update;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Message;
use Bitrix\Tasks\V2\Public\Command\Task\Result\AddResultCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Result\AddResultFromMessageCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Result\DeleteResultCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Result\UpdateResultCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\Result\Builder\TaskResultParamsDirector;
use Bitrix\Tasks\V2\Public\Provider\Params\Result\Builder\TaskResultParamsFromRequestBuilder;
use Bitrix\Tasks\V2\Public\Provider\TaskResultProvider;

#[DtoType(ResultDto::class)]
class Result extends RestController
{
	use ValidateDtoTrait;

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

	/**
	 * @throws AccessDeniedException
	 * @throws DtoValidationException
	 * @throws RequestValidationException
	 * @throws CommandException
	 */
	public function addAction(
		AddRequest $request,
		TaskResultProvider $taskResultProvider,
		ResultDtoMapper $mapper,
	): GetResponse
	{
		/** @var ResultDto $requestDto */
		$requestDto = $request->fields->getAsDto();
		if (!$this->validateDto($requestDto, 'add'))
		{
			throw new DtoValidationException($this->getErrors());
		}

		$requestEntity = $mapper->mapToEntityForAddRequest($requestDto);

		$accessProvider = new Add();
		if (!$accessProvider->check($requestEntity, $this->context))
		{
			throw new AccessDeniedException();
		}

		try
		{
			$commandResult = (new AddResultCommand(
				result: $requestEntity,
				userId: $this->userId,
				useConsistency: true,
			))->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}

		if (!$commandResult->isSuccess())
		{
			throw new RequestValidationException($commandResult->getErrors());
		}

		$responseEntity = $taskResultProvider->getResultById($commandResult->getId(), $this->userId);

		$responseDto = $mapper->mapToDto($responseEntity);

		return new GetResponse($responseDto);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws DtoValidationException
	 * @throws RequestValidationException
	 * @throws CommandException
	 */
	public function addFromChatMessageAction(
		AddRequest $request,
		TaskResultProvider $taskResultProvider,
		ResultDtoMapper $mapper,
	): GetResponse
	{
		/** @var ResultDto $requestDto */
		$requestDto = $request->fields->getAsDto();
		if (!$this->validateDto($requestDto, 'addFromChatMessage'))
		{
			throw new DtoValidationException($this->getErrors());
		}

		$messageEntity = new Message($requestDto->messageId);

		$accessProvider = new Im\Access\Result\Permission\Add();
		if (!$accessProvider->check($messageEntity, $this->context))
		{
			throw new AccessDeniedException();
		}

		try
		{
			$commandResult = (new AddResultFromMessageCommand(
				userId: $this->userId,
				messageId: $messageEntity->id,
			))->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}

		if (!$commandResult->isSuccess())
		{
			throw new RequestValidationException($commandResult->getErrors());
		}

		$responseEntity = $taskResultProvider->getResultById($commandResult->getId(), $this->userId);

		$responseDto = $mapper->mapToDto($responseEntity);

		return new GetResponse($responseDto);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws CommandException
	 * @throws DtoValidationException
	 * @throws RequestValidationException
	 * @throws RequiredFieldInRequestException
	 */
	public function updateAction(
		UpdateRequest $request,
		TaskResultProvider $taskResultProvider,
		ResultDtoMapper $mapper,
	): GetResponse
	{
		if ($request->id === null)
		{
			throw new RequiredFieldInRequestException('id');
		}

		/** @var ResultDto $requestDto */
		$requestDto = $request->fields->getAsDto();
		if (!$this->validateDto($requestDto, 'update'))
		{
			throw new DtoValidationException($this->getErrors());
		}

		$requestDto->id = (int)$request->id;

		$requestEntity = $mapper->mapToEntity($requestDto);

		$accessProvider = new Update();
		if (!$accessProvider->check($requestEntity, $this->context))
		{
			throw new AccessDeniedException();
		}

		try
		{
			$commandResult = (new UpdateResultCommand(
				result: $requestEntity,
				userId: $this->userId,
				useConsistency: true,
			))->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}

		if (!$commandResult->isSuccess())
		{
			throw new DtoValidationException($commandResult->getErrors());
		}

		$responseEntity = $taskResultProvider->getResultById($commandResult->getId(), $this->userId);

		$responseDto = $mapper->mapToDto($responseEntity);

		return new GetResponse($responseDto);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws CommandException
	 * @throws RequestValidationException
	 * @throws RequiredFieldInRequestException
	 */
	public function deleteAction(DeleteRequest $request): DeleteResponse
	{
		if ($request->id === null)
		{
			throw new RequiredFieldInRequestException('id');
		}

		$requestEntity = new Entity\Result(id: (int)$request->id);

		$accessProvider = new Delete();
		if (!$accessProvider->check($requestEntity, $this->context))
		{
			throw new AccessDeniedException();
		}

		try
		{
			$commandResult = (new DeleteResultCommand(
				result: $requestEntity,
				userId: $this->userId,
				useConsistency: true,
			))->run();
		}
		catch (CommandValidationException $exception)
		{
			throw new RequestValidationException($exception->getValidationErrors());
		}

		if (!$commandResult->isSuccess())
		{
			throw new RequestValidationException($commandResult->getErrors());
		}

		return new DeleteResponse(true);
	}

	/**
	 * @throws AccessDeniedException
	 * @throws SystemException
	 */
	public function listAction(
		ListRequest $request,
		TaskResultParamsDirector $taskResultParamsDirector,
		TaskResultProvider $taskResultProvider,
		ResultDtoMapper $mapper,
	): ListResponse
	{
		$conditions = $request->filter?->getSimpleFilterConditions();
		if (!isset($conditions['taskId']))
		{
			throw new RequiredFieldInRequestException('filter');
		}

		$taskId = (int)$conditions['taskId'];
		$requestEntity = new Entity\Result(id: $taskId);

		$accessProvider = new Read();
		if (!$accessProvider->check($requestEntity, $this->context))
		{
			throw new AccessDeniedException();
		}

		$params = $taskResultParamsDirector->produce(
			new TaskResultParamsFromRequestBuilder($request),
		);

		$results = $taskResultProvider->getList(
			$params,
			$this->userId,
		);

		$responseDtoCollection = $mapper->mapToDtoCollection($results, $request);

		return new ListResponse($responseDtoCollection);
	}
}
