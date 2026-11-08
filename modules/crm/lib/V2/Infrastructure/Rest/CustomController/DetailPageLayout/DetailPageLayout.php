<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\DetailPageLayout;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout\LayoutDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\DetailPageLayout\LayoutDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\AddLayoutRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\DeleteLayoutRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\GetCommonLayoutIdRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\GetCurrentRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\GetLayoutRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\GetPersonalLayoutIdRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\ListLayoutRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\SetCommonForAllRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\SetCurrentRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\DetailPageLayout\UpdateLayoutRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Response\DetailPageLayout\LayoutIdResponse;
use Bitrix\Crm\V2\Public\Command\Layout\AddCommand;
use Bitrix\Crm\V2\Public\Command\Layout\DeleteCommand;
use Bitrix\Crm\V2\Public\Command\Layout\SetCommonForAllCommand;
use Bitrix\Crm\V2\Public\Command\Layout\SetCurrentLayoutCommand;
use Bitrix\Crm\V2\Public\Command\Layout\UpdateCommand;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutContext;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutContextResolver;
use Bitrix\Crm\V2\Public\Entity\Layout\LayoutId;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Layout\Provider;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

#[DtoType(LayoutDto::class)]
class DetailPageLayout extends AbstractController
{
	public function getCurrentLayoutAction(GetCurrentRequest $request, EntityType $entityType): GetResponse
	{
		$context = $this->resolveContext($entityType, $request->categoryId);
		$data = $this->getResultData($this->callProvider('getCurrent', [$context, $request->userId]));

		return new GetResponse($this->getMapper()->getDtoByLayout($data['layout']));
	}

	public function setCurrentAction(SetCurrentRequest $request, EntityType $entityType): UpdateResponse
	{
		$layoutId = $this->parseLayoutId($request->layoutId);
		$context = $this->resolveContext($entityType, $request->categoryId, $layoutId);
		$result = $this->runCommand(
			SetCurrentLayoutCommand::class,
			[$this->userId, $context, $layoutId, $request->userId],
		);
		$this->getResultData($result);

		return new UpdateResponse(true);
	}

	public function getPersonalLayoutIdAction(
		GetPersonalLayoutIdRequest $request,
		EntityType $entityType,
	): LayoutIdResponse
	{
		$context = $this->resolveContext($entityType, $request->categoryId);
		$data = $this->getResultData($this->callProvider('getPersonalLayoutId', [$context]));

		return new LayoutIdResponse($data['layoutId']->getValue());
	}

	public function getCommonLayoutIdAction(
		GetCommonLayoutIdRequest $request,
		EntityType $entityType,
	): LayoutIdResponse
	{
		$context = $this->resolveContext($entityType, $request->categoryId);
		$data = $this->getResultData($this->callProvider('getCommonLayoutId', [$context]));

		return new LayoutIdResponse($data['layoutId']->getValue());
	}

	public function setCommonForAllAction(SetCommonForAllRequest $request, EntityType $entityType): UpdateResponse
	{
		$context = $this->resolveContext($entityType, $request->categoryId);
		$result = $this->runCommand(SetCommonForAllCommand::class, [$this->userId, $context]);
		$this->getResultData($result);

		return new UpdateResponse(true);
	}

	public function addAction(AddLayoutRequest $request, EntityType $entityType): AddResponse
	{
		$context = $this->resolveContext($entityType, $request->categoryId);
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
		$fields = $this->getResultData($this->getMapper()->getFieldsByDto($dto));
		$result = $this->runCommand(AddCommand::class, [
			$this->userId,
			$context,
			$fields['name'],
			$fields['configuration'],
			$fields['audience'],
		]);
		$data = $this->getResultData($result);

		return new AddResponse($data['layout']->getId()->getCustomId());
	}

	public function updateAction(UpdateLayoutRequest $request, EntityType $entityType): UpdateResponse
	{
		$layoutId = $this->parseLayoutId($request->id);
		$context = $this->resolveContext($entityType, $request->categoryId, $layoutId);
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);
		$fields = $this->getResultData($this->getMapper()->getFieldsByDto($dto));
		$result = $this->runCommand(UpdateCommand::class, [
			$this->userId,
			$context,
			$layoutId,
			$fields['configuration'] ?? null,
			$fields['name'] ?? null,
			$fields['audience'] ?? null,
			$request->userId,
		]);
		$this->getResultData($result);

		return new UpdateResponse(true);
	}

	public function deleteAction(DeleteLayoutRequest $request, EntityType $entityType): DeleteResponse
	{
		$layoutId = $this->parseLayoutId($request->id);
		$context = $this->resolveContext($entityType, $request->categoryId, $layoutId);
		$result = $this->runCommand(DeleteCommand::class, [$this->userId, $context, $layoutId, $request->userId]);
		$this->getResultData($result);

		return new DeleteResponse(true);
	}

	public function getAction(GetLayoutRequest $request, EntityType $entityType): GetResponse
	{
		$layoutId = $this->parseLayoutId($request->id);
		$context = $this->resolveContext($entityType, $request->categoryId, $layoutId);
		$data = $this->getResultData($this->callProvider('get', [$context, $layoutId, $request->userId]));

		return new GetResponse($this->getMapper()->getDtoByLayout($data['layout']));
	}

	public function listAction(ListLayoutRequest $request, EntityType $entityType): ListResponse
	{
		$context = $this->resolveContext($entityType, $request->filter->getCategoryId());
		$pager = $request->pagination === null
			? new Pager()
			: new Pager($request->pagination->getLimit(), $request->pagination->getOffset())
		;
		$data = $this->getResultData($this->callProvider('list', [$context, $request->filter->getUserId(), $pager]));

		return new ListResponse($this->getMapper()->getDtoCollectionByLayouts($data['layouts']));
	}

	private function parseLayoutId(string $value): LayoutId
	{
		return $this->getResultData(LayoutId::fromString($value))['layoutId'];
	}

	private function resolveContext(
		EntityType $entityType,
		int $categoryId,
		?LayoutId $layoutId = null,
	): LayoutContext
	{
		$result = $this->resolveContextResult($entityType->getId(), $categoryId, $layoutId);

		return $this->getResultData($result)['context'];
	}

	private function getResultData(Result $result): array
	{
		(new LayoutErrorMapper())->throwIfFailed($result);

		return $result->getData();
	}

	protected function getProvider(): Provider
	{
		return new Provider($this->userId);
	}

	protected function resolveContextResult(int $entityTypeId, int $categoryId, ?LayoutId $layoutId): Result
	{
		return (new LayoutContextResolver())->resolve($entityTypeId, $categoryId, $layoutId);
	}

	protected function callProvider(string $method, array $arguments): Result
	{
		$provider = $this->getProvider();

		return match ($method) {
			'getCurrent' => $provider->getCurrent(...$arguments),
			'getPersonalLayoutId' => $provider->getPersonalLayoutId(...$arguments),
			'getCommonLayoutId' => $provider->getCommonLayoutId(...$arguments),
			'get' => $provider->get(...$arguments),
			'list' => $provider->list(...$arguments),
		};
	}

	/** @param class-string<\Bitrix\Crm\V2\Public\Command\Layout\AbstractLayoutCommand> $commandClass */
	protected function runCommand(string $commandClass, array $arguments): Result
	{
		$command = match ($commandClass) {
			AddCommand::class => new AddCommand(...$arguments),
			UpdateCommand::class => new UpdateCommand(...$arguments),
			DeleteCommand::class => new DeleteCommand(...$arguments),
			SetCurrentLayoutCommand::class => new SetCurrentLayoutCommand(...$arguments),
			SetCommonForAllCommand::class => new SetCommonForAllCommand(...$arguments),
		};

		return $command->run();
	}

	protected function getMapper(): LayoutDtoMapper
	{
		return new LayoutDtoMapper();
	}
}
