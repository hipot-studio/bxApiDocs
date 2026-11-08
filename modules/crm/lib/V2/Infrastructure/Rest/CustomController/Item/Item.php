<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\AggregateItemRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ItemDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\FieldValueElementMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ItemFilterMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ItemListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ItemDtoGenerator;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Tracking\TrackingSourceNotAppliedException;
use Bitrix\Crm\V2\Infrastructure\Rest\Provider\Item\RestItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AbstractItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AddFieldValueRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AddItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\AggregateItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\DeleteItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\DeleteFieldValueRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\GetItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ListItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\UpdateItemRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FileFieldInputBag;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\FileFieldInputExtractor;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\ValidValuesCache;
use Bitrix\Crm\V2\Internal\Integration\Rest\File\FileUploadGateway;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\File\FileFieldWriteService;
use Bitrix\Crm\V2\Internal\Service\Item\File\SystemFileFieldHandlerRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\ItemOperationRunner;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\PreparedItemOperation;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\AddFieldValueCommand;
use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\DeleteItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\DeleteFieldValueCommand;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCategoriesInterface;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownFilterFieldException;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownSelectFieldException;
use Bitrix\Crm\V2\Public\Provider\Item\Exception\UnknownSortFieldException;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\Generator;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\AggregateResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Structure;
use Bitrix\Rest\V3\Structure\Aggregation\AggregationResultStructure;
use Bitrix\Rest\V3\Structure\Aggregation\AggregationType;
use Bitrix\Rest\V3\Structure\Aggregation\ResultItem;
use ReflectionClass;

class Item extends AbstractController
{
	protected function processBeforeAction(Action $action): bool
	{
		if (!parent::processBeforeAction($action))
		{
			return false;
		}
		if (!in_array($action->getName(), ['get', 'list', 'aggregate'], true))
		{
			return true;
		}

		$generatedDto = (new ItemDtoGenerator())->generate($this->getEntityType()->getId());
		$dtoClass = Generator::generateByDto($generatedDto);
		if (Structure::getDto($dtoClass) === null)
		{
			Structure::addDto($dtoClass::create());
		}

		return true;
	}

	public function addAction(AddItemRequest $request, EntityType $entityType): AddResponse
	{
		$fileFields = $this->extractFileFields($request, $entityType, FileFieldInputExtractor::OPERATION_ADD);
		$dto = $this->convertToDtoForRequest($request->fields, (RequiredGroup::Add)->value, $this->getRequest());

		$item = $this->getMapper($entityType)->getItemByDto($dto, validateForAdd: true);
		$routeCategoryId = $this->getRestrictedRouteCategoryId($entityType);
		if ($item instanceof HasCategoriesInterface && $routeCategoryId !== null)
		{
			$item->setCategoryId($routeCategoryId);
		}
		$this->prepareItemForAdd($item);
		$result = $this->runAddItemCommand($item, $fileFields);

		if (!$result->isSuccess())
		{
			self::throwIfTrackingSourceRefused($result);

			throw new RequestValidationException(self::normalizeWriteErrors(
				$result->getErrors(),
				$this->getWriteErrorFieldNameResolver($request, $entityType, $fileFields),
			));
		}

		return new AddResponse($item->getId());
	}

	public function updateAction(UpdateItemRequest $request, EntityType $entityType): UpdateResponse
	{
		$fileFields = $this->extractFileFields($request, $entityType, FileFieldInputExtractor::OPERATION_UPDATE);
		$dto = $this->convertToDtoForRequest($request->fields, (RequiredGroup::Update)->value, $this->getRequest());

		$item = $this->getMapper($entityType)->getItemByDto($dto);
		$item->setId($request->id);
		$provider = $this->getRestItemProvider($entityType);
		// Only a route restricted to a category needs it; leads and quotes have no categoryId to select.
		$storedItemSelect = $this->getRestrictedRouteCategoryId() === null ? ['id'] : ['id', 'categoryId'];
		$storedItem = $provider->getById($request->id, $storedItemSelect);
		if ($storedItem === null)
		{
			throw new EntityNotFoundException($request->id);
		}
		$this->assertRestrictedItemCategoryAllowed($storedItem);

		if ($this->shouldSkipUpdate($item, $fileFields)) // for response consistency on empty fields
		{
			return new UpdateResponse();
		}

		try
		{
			$result = $this->runUpdateItemCommand($item, $fileFields);
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($item->getId());
		}

		if (!$result->isSuccess())
		{
			self::throwIfTrackingSourceRefused($result);

			throw new RequestValidationException(self::normalizeWriteErrors(
				$result->getErrors(),
				$this->getWriteErrorFieldNameResolver($request, $entityType, $fileFields),
			));
		}

		return new UpdateResponse();
	}

	private function convertToDtoForRequest(FieldsStructure $fields, string $group, ?HttpRequest $request): Dto
	{
		return ValidValuesCache::runForRequest(
			$request,
			static fn (): Dto => $fields->convertToDto($group),
		);
	}

	public function addFieldValueAction(
		AddFieldValueRequest $request,
		EntityType $entityType,
	): UpdateResponse
	{
		$storedItem = $this->getRestItemProvider($entityType)->getById($request->id, ['id', 'categoryId']);
		if ($storedItem === null)
		{
			throw new EntityNotFoundException($request->id);
		}
		$this->assertRestrictedItemCategoryAllowed($storedItem);
		$itemId = new ItemId($entityType, $request->id);
		$this->validateFieldScope($request);
		$fieldValueElement = (new FieldValueElementMapper())->map(
			$entityType,
			$request->fieldName,
			$request->value,
		);

		try
		{
			$result = (new AddFieldValueCommand(
				$itemId,
				$fieldValueElement,
				$this->userId,
			))
				->setScope(Scope::Rest)
				->withoutRequiredUserFieldsCheck(!$this->isRequiredCheckEnabled())
				->run()
			;
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($itemId->getId());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException(self::normalizeWriteErrors(
				$result->getErrors(),
				$this->getWriteErrorFieldNameResolver($request, $entityType),
			));
		}

		return new UpdateResponse();
	}

	public function deleteAction(DeleteItemRequest $request, EntityType $entityType): DeleteResponse
	{
		$storedItem = $this->getRestItemProvider($entityType)->getById($request->id, ['id', 'categoryId']);
		if ($storedItem === null)
		{
			throw new EntityNotFoundException($request->id);
		}
		$this->assertRestrictedItemCategoryAllowed($storedItem);
		$itemId = new ItemId($entityType, $request->id);

		try
		{
			$result = (new DeleteItemCommand($itemId, $this->userId))
				->setScope(Scope::Rest)
				->run()
			;
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($itemId->getId());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException(self::normalizeWriteErrors(
				$result->getErrors(),
				$this->getWriteErrorFieldNameResolver($request, $entityType),
			));
		}

		return new DeleteResponse();
	}

	public function deleteFieldValueAction(
		DeleteFieldValueRequest $request,
		EntityType $entityType,
	): UpdateResponse
	{
		$storedItem = $this->getRestItemProvider($entityType)->getById($request->id, ['id', 'categoryId']);
		if ($storedItem === null)
		{
			throw new EntityNotFoundException($request->id);
		}
		$this->assertRestrictedItemCategoryAllowed($storedItem);
		$itemId = new ItemId($entityType, $request->id);
		$this->validateFieldScope($request);
		$value = $this->normalizeDeleteFieldValue($request, $entityType);
		$fieldValueElement = (new FieldValueElementMapper())->map(
			$entityType,
			$request->fieldName,
			$value,
			validateValueExistence: false,
		);

		try
		{
			$result = (new DeleteFieldValueCommand(
				$itemId,
				$fieldValueElement,
				$this->userId,
			))
				->setScope(Scope::Rest)
				->withoutRequiredUserFieldsCheck(!$this->isRequiredCheckEnabled())
				->run()
			;
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($itemId->getId());
		}

		if (!$result->isSuccess())
		{
			throw new RequestValidationException(self::normalizeWriteErrors(
				$result->getErrors(),
				$this->getWriteErrorFieldNameResolver($request, $entityType),
			));
		}

		return new UpdateResponse();
	}

	/**
	 * The refusals of the tracking source keep their own answers: an invalid source points at the input
	 * field, and a source not applied after the write carries the id of the written item.
	 */
	private static function throwIfTrackingSourceRefused(Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			$data = $error->getCustomData();
			if ($error->getCode() === ItemOperationRunner::ERROR_TRACKING_SOURCE_NOT_APPLIED)
			{
				throw new TrackingSourceNotAppliedException(
					(int)($data['itemId'] ?? 0),
					(bool)($data['conflict'] ?? false),
				);
			}

			if ($error->getCode() === ItemOperationRunner::ERROR_TRACKING_SOURCE_INVALID)
			{
				$fieldPath = 'fields.' . \Bitrix\Crm\V2\Public\Entity\Item\Item::trackingSourceId;

				throw new RequestValidationException([new Error($error->getMessage(), $fieldPath)]);
			}
		}
	}

	/**
	 * @param Error[] $errors
	 * @return Error[]
	 */
	private static function normalizeWriteErrors(array $errors, ?callable $fieldNameResolver = null): array
	{
		return array_map(
			static function(Error $error) use ($fieldNameResolver): Error
			{
				$fieldName = (string)($error->getCustomData()['field'] ?? $error->getCode());
				if ($fieldNameResolver !== null)
				{
					$fieldName = $fieldNameResolver($fieldName);
				}

				return new Error(
					$error->getLocalizableMessage() ?? $error->getMessage(),
					$fieldName,
					$error->getCustomData(),
				);
			},
			$errors,
		);
	}

	private function getWriteErrorFieldNameResolver(
		AbstractItemRequest $request,
		EntityType $entityType,
		?FileFieldInputBag $fileFields = null,
	): ?\Closure
	{
		$fieldNames = self::getWriteRequestFieldNames($request);
		$systemFieldNames = $fileFields?->getInputFieldNamesByItemFieldName() ?? [];
		if ($fieldNames === [] && $systemFieldNames === [])
		{
			return null;
		}

		$mapper = $this->getMapper($entityType);

		return static function (string $fieldName) use ($mapper, $fieldNames, $systemFieldNames): string
		{
			return $systemFieldNames[$fieldName]
				?? $mapper->getDtoFieldNameByItemFieldName($fieldName, $fieldNames);
		};
	}

	/**
	 * @return string[]
	 */
	private static function getWriteRequestFieldNames(AbstractItemRequest $request): array
	{
		if ($request instanceof AddItemRequest || $request instanceof UpdateItemRequest)
		{
			return array_keys(array_merge($request->fields->getItems(), $request->fields->getUserFields()));
		}

		if ($request instanceof AddFieldValueRequest || $request instanceof DeleteFieldValueRequest)
		{
			return [$request->fieldName];
		}

		return [];
	}

	public function getAction(GetItemRequest $request, EntityType $entityType): GetResponse
	{
		$provider = $this->getRestItemProvider($entityType);
		$mapper = $this->getReadMapper($entityType);
		$select = $mapper->getItemSelectFromDtoFieldNames($request);

		try
		{
			$item = $provider->getById($request->id, $select);
		}
		catch (UnknownSelectFieldException $exception)
		{
			throw $this->createUnknownDtoPropertyException(
				$request->getDtoClass(),
				$exception->getFieldName(),
			);
		}
		if (!$item)
		{
			throw new EntityNotFoundException($request->id);
		}
		$this->assertRestrictedItemCategoryAllowed($item);

		$dto = $mapper->getDtoByItemAndRequest($item, $request);

		return new GetResponse($dto);
	}

	public function listAction(ListItemRequest $request, EntityType $entityType): ListResponse
	{
		$settings = EntityTypeSettings::of($entityType);
		$itemDtoMapper = $this->getReadMapper($entityType);
		$requestMapper = new ItemListRequestMapper(
			$itemDtoMapper,
			new ItemFilterMapper($settings, $itemDtoMapper, userId: $this->userId),
		);
		$select = $requestMapper->mapSelect($request);
		$filter = $requestMapper->mapFilter($request);
		$filter = $this->withRouteCategory($filter);
		$sort = $requestMapper->mapSort($request);
		$pager = $requestMapper->mapPager($request);
		$items = $this->executeListProviderCall(
			fn(): ItemCollection => $this->getRestItemProvider($entityType)
				->getList($select, $filter, $sort, $pager),
			$request->getDtoClass(),
		);

		return new ListResponse($itemDtoMapper->getDtoCollectionByItemsAndRequest($items, $request));
	}

	public function aggregateAction(AggregateItemRequest $request, EntityType $entityType): AggregateResponse
	{
		$settings = EntityTypeSettings::of($entityType);
		$itemDtoMapper = $this->getReadMapper($entityType);
		$requestMapper = new AggregateItemRequestMapper(
			new ItemFilterMapper($settings, $itemDtoMapper, userId: $this->userId),
		);
		$requestMapper->mapSelect($request);
		$filter = $this->withRouteCategory($requestMapper->mapFilter($request));
		$count = $this->executeListProviderCall(
			fn(): int => $this->getRestItemProvider($entityType)->getCount(
				$filter,
			),
			$request->getDtoClass(),
		);
		$result = new AggregationResultStructure();
		$result->add(new ResultItem(
			AggregationType::Count,
			'id',
			$count,
		));

		return new AggregateResponse($result);
	}

	protected function assertItemCategoryAllowed(
		\Bitrix\Crm\V2\Public\Entity\Item\Item $item,
		int $expectedCategoryId,
	): void
	{
		if (!$item instanceof HasCategoriesInterface || $item->getCategoryId() !== $expectedCategoryId)
		{
			throw new EntityNotFoundException($item->getId());
		}
	}

	private function assertRestrictedItemCategoryAllowed(
		\Bitrix\Crm\V2\Public\Entity\Item\Item $item,
	): void
	{
		$categoryId = $this->getRestrictedRouteCategoryId();
		if ($categoryId !== null)
		{
			$this->assertItemCategoryAllowed($item, $categoryId);
		}
	}

	private function withRouteCategory(?ItemFilter $filter): ?ItemFilter
	{
		$categoryId = $this->getRestrictedRouteCategoryId();
		if ($categoryId === null)
		{
			return $filter;
		}
		if ($filter === null)
		{
			return new ItemFilter(['categoryId' => $categoryId]);
		}

		$raw = $filter->getRaw();
		if ($raw instanceof ConditionTree)
		{
			$raw = (new ConditionTree())
				->logic(ConditionTree::LOGIC_AND)
				->addCondition(clone $raw)
				->where('categoryId', $categoryId)
			;

			return new ItemFilter($raw);
		}

		$raw['categoryId'] = $categoryId;

		return new ItemFilter($raw);
	}

	private function getRestrictedRouteCategoryId(): ?int
	{
		return $this->getRouteCategoryId();
	}

	/**
	 * @param callable(): mixed $providerCall
	 */
	private function executeListProviderCall(callable $providerCall, string $dtoClass): mixed
	{
		try
		{
			return $providerCall();
		}
		catch (
			UnknownSelectFieldException
			| UnknownFilterFieldException
			| UnknownSortFieldException $exception
		)
		{
			throw $this->createUnknownDtoPropertyException($dtoClass, $exception->getFieldName());
		}
	}

	private function createUnknownDtoPropertyException(
		string $dtoClass,
		string $fieldName,
	): UnknownDtoPropertyException
	{
		$dto = Structure::getDto($dtoClass);
		$dtoShortName = $dto?->getShortName()
			?? (new ReflectionClass($dtoClass))->getShortName();

		return new UnknownDtoPropertyException($dtoShortName, $fieldName);
	}

	private function validateFieldScope(AbstractItemRequest $request): void
	{
		$scope = $request->getOptions()['scope'] ?? null;
		$availableFields = $scope?->fields ?? [];
		if ($availableFields !== [] && !in_array($request->fieldName, $availableFields, true))
		{
			throw $this->createUnknownDtoPropertyException($request->getDtoClass(), $request->fieldName);
		}
	}

	protected function getMapper(EntityType $entityType): ItemDtoMapper
	{
		return new ItemDtoMapper(EntityTypeSettings::of($entityType));
	}

	protected function getReadMapper(EntityType $entityType): ItemDtoMapper
	{
		return new ItemDtoMapper(EntityTypeSettings::of($entityType), restServer: $this->getServer());
	}

	protected function getRestItemProvider(EntityType $entityType): RestItemProvider
	{
		return new RestItemProvider($entityType, $this->userId);
	}

	protected function runAddItemCommand(
		\Bitrix\Crm\V2\Public\Entity\Item\Item $item,
		FileFieldInputBag $fileFields,
	): Result
	{
		$command
			= (new AddItemCommand($item, $this->userId))
				->setScope(Scope::Rest)
				->withoutRequiredUserFieldsCheck(!$this->isRequiredCheckEnabled())
		;

		return $this->runItemWriteCommand($command, $fileFields);
	}

	protected function prepareItemForAdd(\Bitrix\Crm\V2\Public\Entity\Item\Item $item): void
	{
	}

	protected function runUpdateItemCommand(
		\Bitrix\Crm\V2\Public\Entity\Item\Item $item,
		FileFieldInputBag $fileFields,
	): Result
	{
		$command
			= (new UpdateItemCommand($item, $this->userId))
				->setScope(Scope::Rest)
				->withoutRequiredUserFieldsCheck(!$this->isRequiredCheckEnabled())
		;

		return $this->runItemWriteCommand($command, $fileFields);
	}

	protected function runItemWriteCommand(AbstractItemCommand $command, FileFieldInputBag $fileFields): Result
	{
		$requests = $fileFields->getWriteRequests();
		if ($requests === [])
		{
			return $command->run();
		}

		return $this->executeFileWriteCommand($command, $requests);
	}

	/**
	 * The files are written by their own service, around which the tracking source of the item is handled
	 * the same way as around a write without files.
	 */
	protected function executeFileWriteCommand(AbstractItemCommand $command, array $requests): Result
	{
		$fileFieldWriteService = new FileFieldWriteService(
			systemFileFieldHandlerRegistry: new SystemFileFieldHandlerRegistry(),
		);

		return (new ItemOperationRunner())->runWithTrackingSource(
			$command,
			static fn(?PreparedItemOperation $prepared): Result => $fileFieldWriteService->execute(
				$command,
				$requests,
				$prepared,
			),
			true,
		);
	}

	protected function shouldSkipUpdate(
		\Bitrix\Crm\V2\Public\Entity\Item\Item $item,
		FileFieldInputBag $fileFields,
	): bool
	{
		return !$item->hasChangedFields() && $fileFields->getWriteRequests() === [];
	}

	protected function extractFileFields(
		AbstractItemRequest $request,
		EntityType $entityType,
		string $operation,
	): FileFieldInputBag
	{
		$bag = (new FileFieldInputExtractor(
			CustomFieldRegistry::getInstance(),
			new FileUploadGateway(),
			new SystemFileFieldHandlerRegistry(),
		))->extract($request, $entityType, $operation);

		return $bag;
	}

	private function normalizeDeleteFieldValue(
		DeleteFieldValueRequest $request,
		EntityType $entityType,
	): mixed
	{
		$descriptor = CustomFieldRegistry::getInstance()
			->getEntityDescriptorsMap($entityType)[$request->fieldName] ?? null;
		if ($descriptor?->type !== 'file')
		{
			return $request->value;
		}

		return (new FileFieldInputExtractor(
			CustomFieldRegistry::getInstance(),
			new FileUploadGateway(),
			new SystemFileFieldHandlerRegistry(),
		))->extractExistingFileReference($request->value, 'value');
	}

	protected function isRequiredCheckEnabled(): bool
	{
		return \Bitrix\Crm\Settings\RestSettings::getCurrent()->isRequiredUserFieldCheckEnabled();
	}
}
