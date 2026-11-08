<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CustomFieldConfigDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Field\CustomFieldConfigMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Service\CustomFieldService;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Context;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\LogicException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Structure;

#[DtoType(CustomFieldConfigDto::class)]
final class Custom extends AbstractController
{
	private ?CustomFieldService $service = null;

	public function listAction(ListRequest $request, EntityType $entityType): ListResponse
	{
		self::refuseMalformedFilter($request);

		$selectedFields = $request->select?->getList() ?: $this->processedScope->fields;
		$fields = $this->getService()->getList(
			$entityType,
			$this->getRouteEntityId(),
			$this->userId,
			$this->getResponseLanguage(),
			$request->filter,
			$request->order,
			$request->pagination,
		);

		return new ListResponse($this->getMapper()->mapCollection($fields, $selectedFields));
	}

	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		$selectedFields = $request->select?->getList() ?: $this->processedScope->fields;
		$field = $this->getService()->getById(
			$entityType,
			$this->getRouteEntityId(),
			(int)$request->id,
			$this->userId,
			$this->getResponseLanguage(),
		);

		return new GetResponse($this->getMapper()->mapOne($field, $selectedFields));
	}

	public function addAction(AddRequest $request, EntityType $entityType): GetResponse
	{
		$this->validateFieldsScope($request->fields);

		/** @var CustomFieldConfigDto $dto */
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
		$field = $this->getService()->add(
			$entityType,
			$this->getRouteEntityId(),
			$this->userId,
			$this->getMapper()->getValuesForAdd($dto, $this->getRouteEntityId(), $this->getResponseLanguage()),
			$this->getResponseLanguage(),
		);

		return new GetResponse($this->getMapper()->mapOne($field, $this->processedScope->fields));
	}

	public function updateAction(UpdateRequest $request, EntityType $entityType): GetResponse
	{
		self::refuseFilterOfChange($request->filter);
		$this->validateFieldsScope($request->fields);

		/** @var CustomFieldConfigDto $dto */
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);
		$field = $this->getService()->update(
			$entityType,
			$this->getRouteEntityId(),
			(int)$request->id,
			$this->userId,
			$this->getMapper()->getValuesForUpdate($dto, $this->getResponseLanguage()),
			$this->getResponseLanguage(),
		);

		return new GetResponse($this->getMapper()->mapOne($field, $this->processedScope->fields));
	}

	public function deleteAction(DeleteRequest $request, EntityType $entityType): DeleteResponse
	{
		self::refuseFilterOfChange($request->filter);

		$this->getService()->delete(
			$entityType,
			$this->getRouteEntityId(),
			(int)$request->id,
			$this->userId,
		);

		return new DeleteResponse();
	}

	private function getMapper(): CustomFieldConfigMapper
	{
		$mapper = $this->getDtoMapper();
		if (!$mapper instanceof CustomFieldConfigMapper)
		{
			throw new LogicException(new SystemException(
				'Route DTO mapper must be ' . CustomFieldConfigMapper::class,
			));
		}

		return $mapper;
	}

	private function validateFieldsScope(FieldsStructure $fields): void
	{
		$availableFields = $this->processedScope->fields;
		if ($availableFields === [])
		{
			return;
		}

		$dto = Structure::getDto(CustomFieldConfigDto::class);
		if ($dto === null)
		{
			return;
		}

		$fields = array_merge($fields->getItems(), $fields->getUserFields());
		foreach (array_keys($fields) as $fieldName)
		{
			if (!in_array($fieldName, $availableFields, true))
			{
				throw new UnknownDtoPropertyException($dto->getShortName(), (string)$fieldName);
			}
		}
	}

	private function getService(): CustomFieldService
	{
		return $this->service ??= new CustomFieldService();
	}

	private function getRouteEntityId(): string
	{
		$entityId = $this->getSourceParametersList()[0]['entityId'] ?? null;
		if (!is_string($entityId) || $entityId === '')
		{
			throw new LogicException(new SystemException(
				'A route of ' . self::class . ' must declare a non-empty "entityId" query param',
			));
		}

		return $entityId;
	}

	private static function refuseFilterOfChange(mixed $filter): void
	{
		if ($filter !== null)
		{
			throw new InvalidFilterException($filter);
		}
	}

	private static function refuseMalformedFilter(ListRequest $request): void
	{
		$filter = Context::getCurrent()->getRequest()->getJsonList()->getValues()['filter'] ?? null;
		if ($filter === null || is_array($filter))
		{
			return;
		}

		FilterStructure::create($filter, $request->getDtoClass(), $request);
	}
}
