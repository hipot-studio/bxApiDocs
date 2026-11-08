<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field\Custom;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\Custom\EnumDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Field\Custom\EnumMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\CustomFieldEnumException;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Custom\Enum\DeleteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Custom\Enum\ListRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Service\CustomFieldEnumService;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldsInRequestFilterPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

final class Enum extends AbstractController
{
	private CustomFieldEnumService $service;

	private EnumMapper $mapper;

	public function __construct(Request $request = null, ?CustomFieldEnumService $service = null)
	{
		$this->mapper = new EnumMapper();
		$this->service = $service ?? new CustomFieldEnumService();
		parent::__construct($request);
	}

	public function listAction(ListRequest $request, EntityType $entityType): ListResponse
	{
		$conditions = $request->filter?->getSimpleFilterConditions() ?? [];
		$fieldId = $conditions['fieldId'] ?? null;
		if (!is_int($fieldId) || $fieldId <= 0)
		{
			throw new RequiredFieldsInRequestFilterPropertyException('filter', ['fieldId']);
		}

		$items = $this->getService()->list(
			$entityType,
			$this->userId,
			$request->filter,
			$request->order,
			$request->pagination,
		);

		return new ListResponse($this->mapper->mapCollection($items, $request->select?->getList() ?? []));
	}

	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		$item = $this->getService()->get($entityType, $this->userId, (int)$request->id);

		return new GetResponse($this->mapper->mapOne($item, $request->select?->getList() ?? []));
	}

	public function addAction(AddRequest $request, EntityType $entityType): GetResponse
	{
		/** @var EnumDto $dto */
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
		self::validateValue($dto);
		$item = $this->executeMutation(fn (): array => $this->getService()->add(
			$entityType,
			$this->userId,
			$dto->fieldId,
			$this->mapper->getValuesForWrite($dto),
		));

		return new GetResponse($this->mapper->mapOne($item));
	}

	public function updateAction(UpdateRequest $request, EntityType $entityType): GetResponse
	{
		/** @var EnumDto $dto */
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);
		self::validateValue($dto);
		$item = $this->executeMutation(fn (): array => $this->getService()->update(
			$entityType,
			$this->userId,
			(int)$request->id,
			$this->mapper->getValuesForWrite($dto),
		));

		return new GetResponse($this->mapper->mapOne($item));
	}

	public function deleteAction(DeleteRequest $request, EntityType $entityType): BooleanResponse
	{
		$result = $this->executeMutation(fn (): bool => $this->getService()->delete(
			$entityType,
			$this->userId,
			$request->id,
		));

		return new BooleanResponse($result);
	}

	private function getService(): CustomFieldEnumService
	{
		return $this->service;
	}

	private function executeMutation(callable $mutation): array|bool
	{
		try
		{
			return $mutation();
		}
		catch (CustomFieldEnumException $exception)
		{
			throw new RequestValidationException([new Error($exception->getMessage(), 'fields.xmlId')]);
		}
	}

	private static function validateValue(EnumDto $dto): void
	{
		if (isset($dto->value) && $dto->value === '')
		{
			throw new InvalidRequestFieldTypeException('fields.value', 'non-empty string');
		}
	}

}
