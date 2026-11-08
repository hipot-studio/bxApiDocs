<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller;

use Bitrix\Intranet\ActionFilter;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Loader;
use Bitrix\Main\Infrastructure\Rest\Dto\UserFieldConfigDto;
use Bitrix\Main\Infrastructure\Rest\Support\LegacyQueryConverter;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

#[DtoType(UserFieldConfigDto::class)]
class Userfieldconfig extends AbstractController
{
	use UserFieldConfigFacadeTrait;

	private const YN_DTO_FIELDS = [
		'multiple',
		'mandatory',
		'showInList',
		'editInList',
		'isSearchable',
	];

	private const YN_ORM_FIELDS = [
		'MULTIPLE',
		'MANDATORY',
		'SHOW_IN_LIST',
		'EDIT_IN_LIST',
		'IS_SEARCHABLE',
	];

	protected function getDefaultPreFilters(): array
	{
		$defaultPreFilters = parent::getDefaultPreFilters();

		if (Loader::includeModule('intranet'))
		{
			$defaultPreFilters[] = new ActionFilter\IntranetUser();
		}

		return $defaultPreFilters;
	}

	#[Scope('userfieldconfig')]
	public function getAction(GetRequest $request, string $moduleId): GetResponse
	{
		$result = $this->executeFacade(
			fn() => $this->getFacade()->get($moduleId, (int)$request->id, $this->getAuthScopes()),
		);
		$field = $result->getData()['field'] ?? null;
		if (!is_array($field))
		{
			throw new Exception\EntityNotFoundException((int)$request->id);
		}

		$selectedFields = $request->select?->getStructuredList() ?? [];

		return new GetResponse($this->mapField($field, $selectedFields));
	}

	#[Scope('userfieldconfig')]
	public function listAction(ListRequest $request, string $moduleId): ListResponse
	{
		$pagination = $request->pagination;
		$result = $this->executeFacade(
			fn() => $this->getFacade()->getList(
				$moduleId,
				$this->prepareSelect($request),
				$this->prepareOrder($request),
				$this->prepareFilter($request->filter),
				$pagination?->getOffset() ?? 0,
				$pagination?->getLimit() ?? PaginationStructure::DEFAULT_LIMIT,
				$this->getAuthScopes(),
			),
		);

		$selectedFields = $request->select?->getStructuredList() ?? [];
		$items = new DtoCollection(UserFieldConfigDto::class);
		foreach ($result->getData()['fields'] as $field)
		{
			$items->add($this->mapField($field, $selectedFields));
		}

		return new ListResponse($items);
	}

	#[Scope('userfieldconfig')]
	public function addAction(AddRequest $request, string $moduleId): GetResponse
	{
		$fields = LegacyQueryConverter::convertBoolFieldsToYn(
			$request->fields->getItems(),
			self::YN_DTO_FIELDS,
		);

		$result = $this->executeFacade(
			fn() => $this->getFacade()->add(
				$moduleId,
				$fields,
				$this->getAuthScopes(),
			),
		);

		return new GetResponse($this->mapField($result->getData()['field']));
	}

	#[Scope('userfieldconfig')]
	public function updateAction(UpdateRequest $request, string $moduleId): GetResponse
	{
		if ($request->filter !== null)
		{
			throw new Exception\InvalidFilterException($request->filter->getList());
		}

		$fields = LegacyQueryConverter::convertBoolFieldsToYn(
			$request->fields->getItems(),
			self::YN_DTO_FIELDS,
		);

		$result = $this->executeFacade(
			fn() => $this->getFacade()->update(
				$moduleId,
				(int)$request->id,
				$fields,
				$this->getAuthScopes(),
			),
		);

		return new GetResponse($this->mapField($result->getData()['field']));
	}

	#[Scope('userfieldconfig')]
	public function deleteAction(DeleteRequest $request, string $moduleId): DeleteResponse
	{
		if ($request->filter !== null)
		{
			throw new Exception\InvalidFilterException($request->filter->getList());
		}

		$this->executeFacade(
			fn() => $this->getFacade()->delete($moduleId, (int)$request->id, $this->getAuthScopes()),
		);

		return new DeleteResponse();
	}

	private function prepareSelect(ListRequest $request): array
	{
		if ($request->select === null || empty($request->select->getList()))
		{
			return ['*'];
		}

		$converter = new Converter(Converter::TO_UPPER | Converter::TO_SNAKE | Converter::VALUES);

		return $converter->process($request->select->getList());
	}

	private function prepareOrder(ListRequest $request): array
	{
		if ($request->order === null)
		{
			return [];
		}

		$converter = new Converter(Converter::TO_UPPER | Converter::TO_SNAKE | Converter::KEYS);

		return $converter->process($request->order->getList());
	}

	private function prepareFilter(?FilterStructure $filter): array
	{
		if ($filter === null)
		{
			return [];
		}

		return LegacyQueryConverter::convertBoolFilterValuesToYn(
			LegacyQueryConverter::filterToLegacy($filter),
			self::YN_ORM_FIELDS,
		);
	}

	private function mapField(array $field, array $selectedFields = []): UserFieldConfigDto
	{
		$dto = new UserFieldConfigDto();
		$emptyFields = $selectedFields === [];
		$shouldMap = static function (string $name) use ($selectedFields, $emptyFields): bool {
			if ($emptyFields)
			{
				return true;
			}

			return in_array($name, $selectedFields, true) || array_key_exists($name, $selectedFields);
		};

		if ($shouldMap('id'))
		{
			$dto->id = isset($field['id']) ? (int)$field['id'] : null;
		}
		if ($shouldMap('entityId'))
		{
			$dto->entityId = $field['entityId'] ?? null;
		}
		if ($shouldMap('fieldName'))
		{
			$dto->fieldName = $field['fieldName'] ?? null;
		}
		if ($shouldMap('userTypeId'))
		{
			$dto->userTypeId = $field['userTypeId'] ?? null;
		}
		if ($shouldMap('xmlId'))
		{
			$dto->xmlId = $field['xmlId'] ?? null;
		}
		if ($shouldMap('sort'))
		{
			$dto->sort = isset($field['sort']) ? (int)$field['sort'] : null;
		}
		if ($shouldMap('multiple'))
		{
			$dto->multiple = LegacyQueryConverter::ynToBool($field['multiple'] ?? null);
		}
		if ($shouldMap('mandatory'))
		{
			$dto->mandatory = LegacyQueryConverter::ynToBool($field['mandatory'] ?? null);
		}
		if ($shouldMap('showFilter'))
		{
			$dto->showFilter = $field['showFilter'] ?? null;
		}
		if ($shouldMap('showInList'))
		{
			$dto->showInList = LegacyQueryConverter::ynToBool($field['showInList'] ?? null);
		}
		if ($shouldMap('editInList'))
		{
			$dto->editInList = LegacyQueryConverter::ynToBool($field['editInList'] ?? null);
		}
		if ($shouldMap('isSearchable'))
		{
			$dto->isSearchable = LegacyQueryConverter::ynToBool($field['isSearchable'] ?? null);
		}
		if ($shouldMap('settings'))
		{
			$dto->settings = is_array($field['settings'] ?? null) ? $field['settings'] : ($field['settings'] ?? null);
		}
		if ($shouldMap('editFormLabel'))
		{
			$dto->editFormLabel = $field['editFormLabel'] ?? null;
		}
		if ($shouldMap('listColumnLabel'))
		{
			$dto->listColumnLabel = $field['listColumnLabel'] ?? null;
		}
		if ($shouldMap('listFilterLabel'))
		{
			$dto->listFilterLabel = $field['listFilterLabel'] ?? null;
		}
		if ($shouldMap('errorMessage'))
		{
			$dto->errorMessage = $field['errorMessage'] ?? null;
		}
		if ($shouldMap('helpMessage'))
		{
			$dto->helpMessage = $field['helpMessage'] ?? null;
		}
		if ($shouldMap('enum'))
		{
			$dto->enum = $field['enum'] ?? null;
		}

		return $dto;
	}

}
