<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\MyCompany;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\RestV3CrudDeal;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ItemDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ItemFilterMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\MyCompany\BasicCompanyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\MyCompanyEntityNotFoundException;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\MyCompany\BasicGetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\MyCompany\BasicListRequest;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort;
use Bitrix\Crm\V2\Public\Provider\MyCompany\MyCompanyBasicProvider;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\McpTool;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\InvalidOrderException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\MethodNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(BasicCompanyDto::class)]
final class Basic extends RestController
{
	protected function processBeforeAction(Action $action): bool
	{
		if (!Feature::enabled(RestV3CrudDeal::class))
		{
			throw new MethodNotFoundException('crm.mycompany.basic.' . strtolower($action->getName()));
		}

		if (!parent::processBeforeAction($action))
		{
			return false;
		}

		if (
			!Container::getInstance()
				->getUserPermissions((int)$this->getCurrentUser()->getId())
				->myCompany()
				->canReadBaseFields()
		)
		{
			throw new AccessDeniedException();
		}

		return true;
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_my_company')]
	public function getAction(BasicGetRequest $request): GetResponse
	{
		$result = $this->getProvider()->getById(
			$request->id,
			$this->getEffectiveSelect($request),
			$this->getNestedSelect($request),
		);
		$this->throwOnGetFailure($result, $request->id);

		return new GetResponse($result->getData()['item']);
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_my_company')]
	public function listAction(BasicListRequest $request): ListResponse
	{
		$result = $this->getProvider()->getList(
			$this->getEffectiveSelect($request),
			$this->mapFilter($request),
			$this->mapOrder($request),
			$this->mapPager($request),
			$this->getNestedSelect($request),
		);
		$this->throwOnListFailure($result);

		return new ListResponse($result->getData()['items']);
	}

	private function getProvider(): MyCompanyBasicProvider
	{
		return new MyCompanyBasicProvider((int)$this->getCurrentUser()->getId());
	}

	/** @return string[] */
	private function getEffectiveSelect(BasicGetRequest|BasicListRequest $request): array
	{
		if ($request->select !== null)
		{
			$selectedFields = array_merge(
				$request->select->getList(),
				$request->select->getUserFields(),
				$request->select->getRelationFields(),
			);
			if ($selectedFields !== [])
			{
				return array_values(array_unique($selectedFields));
			}
		}

		$scope = $request->getOptions()['scope'] ?? null;
		$scopeFields = $scope?->fields ?? [];

		return $scopeFields !== [] ? array_values($scopeFields) : ['id', 'title'];
	}

	/** @return array<string, string[]> */
	private function getNestedSelect(BasicGetRequest|BasicListRequest $request): array
	{
		if ($request->select === null)
		{
			return [];
		}

		$nestedSelect = [];
		foreach ($request->select->getRelationFields() as $fieldName)
		{
			if (!$request->select->isFullRelationSelected($fieldName))
			{
				$nestedSelect[$fieldName] = $request->select->getStructuredList()[$fieldName] ?? [];
			}
		}

		return $nestedSelect;
	}

	private function mapFilter(BasicListRequest $request): ?ItemFilter
	{
		if ($request->filter === null)
		{
			return null;
		}

		$settings = EntityTypeSettings::of(EntityType::company());
		$itemDtoMapper = new ItemDtoMapper($settings);

		return new ItemFilter((new ItemFilterMapper($settings, $itemDtoMapper))->map(
			$request->filter,
			$request->getDtoClass()::create(),
		));
	}

	private function mapOrder(BasicListRequest $request): ItemSort
	{
		$order = $request->order?->getList() ?? [];
		if ($order === [])
		{
			return new ItemSort(['id' => 'ASC']);
		}

		$scopeFields = $request->getOptions()['scope']?->fields ?? [];
		foreach (array_keys($order) as $field)
		{
			if ($scopeFields !== [] && !in_array($field, $scopeFields, true))
			{
				throw new InvalidOrderException($field);
			}
		}

		if (!array_key_exists('id', $order))
		{
			$order['id'] = end($order);
		}

		return new ItemSort($order);
	}

	private function mapPager(BasicListRequest $request): Pager
	{
		if ($request->pagination === null)
		{
			return new Pager();
		}

		$limit = $request->pagination->getLimit();
		$offset = $request->pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return new Pager($limit, $offset);
	}

	private function throwOnGetFailure(Result $result, int $id): void
	{
		if ($result->isSuccess())
		{
			return;
		}

		foreach ($result->getErrors() as $error)
		{
			switch ($error->getCode())
			{
				case 'CRM_MY_COMPANY_NOT_FOUND':
					throw new MyCompanyEntityNotFoundException($id);
				case 'CRM_MY_COMPANY_ACCESS_DENIED':
					throw new AccessDeniedException();
				case 'CRM_MY_COMPANY_VALIDATION':
					throw new RequestValidationException($result->getErrors());
			}
		}

		$this->throwInternalReadError($result);
	}

	private function throwOnListFailure(Result $result): void
	{
		if ($result->isSuccess())
		{
			return;
		}

		foreach ($result->getErrors() as $error)
		{
			switch ($error->getCode())
			{
				case 'CRM_MY_COMPANY_ACCESS_DENIED':
					throw new AccessDeniedException();
				case 'CRM_MY_COMPANY_VALIDATION':
					throw new RequestValidationException($result->getErrors());
			}
		}

		$this->throwInternalReadError($result);
	}

	private function throwInternalReadError(Result $result): never
	{
		throw new InternalException(new \RuntimeException(
			$result->getErrorMessages()[0] ?? 'Failed to read my company data.',
		));
	}
}
