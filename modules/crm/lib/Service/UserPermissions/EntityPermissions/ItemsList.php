<?php

namespace Bitrix\Crm\Service\UserPermissions\EntityPermissions;

use Bitrix\Crm\Security\QueryBuilder;
use Bitrix\Crm\Security\QueryBuilder\OptionsBuilder;
use Bitrix\Crm\Security\QueryBuilder\Result\JoinWithUnionSpecification;
use Bitrix\Crm\Security\QueryBuilder\Result\RawQueryObserverUnionResult;
use Bitrix\Crm\Security\QueryBuilder\Result\RawQueryResult;
use Bitrix\Crm\Security\QueryBuilderFactory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->itemsList()
 */

class ItemsList
{
	private const QUERY_RESULT_CACHE_LIMIT = 64;

	private static array $queryResultCache = [];

	/**
	 * @internal
	 */
	public static function clearQueryResultCache(): void
	{
		self::$queryResultCache = [];
	}

	public function __construct(protected readonly UserPermissions $userPermissions)
	{
	}

	public function createQueryBuilder(
		string|array $permissionEntityTypes,
		QueryBuilder\QueryBuilderOptions $options = null
	): QueryBuilder
	{
		$queryBuilderFactory = QueryBuilderFactory::getInstance();

		return $queryBuilderFactory->make((array)$permissionEntityTypes, $this->userPermissions, $options);
	}

	public function applyAvailableItemsFilter(
		?array $filter,
		array $permissionEntityTypes,
		?string $operation = UserPermissions::OPERATION_READ,
		?string $primary = 'ID'
	): array
	{
		$queryResult =new RawQueryResult(
			identityColumnName: $primary ?? 'ID'
		);

		if (JoinWithUnionSpecification::getInstance()->isSatisfiedBy($filter ?? []))
		{
			$queryResult = new RawQueryObserverUnionResult(identityColumnName: $primary ?? 'ID');
		}

		$filter = $filter ?? [];
		$optionsBuilder = new OptionsBuilder($queryResult);
		$optionsBuilder->setSkipCheckOtherEntityTypes(!empty($permissionEntityTypes));

		if ($operation)
		{
			$optionsBuilder->setOperations((array)$operation);
		}
		$result = $this->buildQueryResult($permissionEntityTypes, $optionsBuilder->build());

		if (!$result->hasRestrictions())
		{
			// no need to apply filter
			return $filter;
		}

		if ($result->hasAccess())
		{
			$expression = $result->getSqlExpression();
		}
		else
		{
			// access denied
			$expression = [0];
		}

		return $this->addRestrictionFilter($filter, $primary, $expression);
	}

	public function applyAvailableItemsGetListParameters(
		?array $parameters,
		array $permissionEntityTypes,
		?string $operation = UserPermissions::OPERATION_READ,
		?string $primary = 'ID'
	): array
	{
		$optionsBuilder = new OptionsBuilder(
			new RawQueryResult(
				identityColumnName: $primary
			)
		);
		$optionsBuilder->setSkipCheckOtherEntityTypes(!empty($permissionEntityTypes));

		if ($operation)
		{
			$optionsBuilder->setOperations((array)$operation);
		}

		$result = $this->buildQueryResult($permissionEntityTypes, $optionsBuilder->build());

		if (!$result->hasRestrictions())
		{
			// no need to apply filter
			return $parameters ?? [];
		}

		if (!$result->hasAccess())
		{
			$parameters['filter'] = $parameters['filter'] ?? [];
			$parameters['filter'] = [
				$parameters['filter'],
				'@' . $primary => [0],
			];

			return $parameters;
		}
		if ($result->isOrmConditionSupport())
		{
			$rf = new ReferenceField(
				'ENTITY',
				$result->getEntity(),
				$result->getOrmConditions(),
				['join_type' => 'INNER']
			);
			$currentRuntime = $parameters['runtime'] ?? [];

			$runtime = array_merge(
				['permissions' => $rf],
				$currentRuntime
			);

			$parameters = array_merge($parameters, ['runtime' => $runtime]);
		}
		else
		{
			$currentFilter = $parameters['filter'] ?? [];

			$parameters['filter'] = $this->addRestrictionFilter(
				$currentFilter,
				$primary,
				$result->getSqlExpression()
			);
		}

		return $parameters;
	}

	public function applyAvailableItemsQueryParameters(
		Query $query,
		array $permissionEntityTypes,
		?string $operation = UserPermissions::OPERATION_READ,
		string $primary = \Bitrix\Crm\Item::FIELD_NAME_ID,
	): Query
	{
		$operations = $operation !== null ? (array)$operation : null;
		$isSkipOtherEntityTypes = !empty($permissionEntityTypes);

		$resultType = new RawQueryResult(identityColumnName: $primary);
		$queryBuilderOptions = (new OptionsBuilder($resultType))
			->setOperationsIfNotNull($operations)
			->setSkipCheckOtherEntityTypes($isSkipOtherEntityTypes)
			->build();

		$result = $this->buildQueryResult($permissionEntityTypes, $queryBuilderOptions);

		if (!$result->hasRestrictions())
		{
			return $query;
		}

		if (!$result->hasAccess())
		{
			$filter = [
				$query->getFilter(),
				"@{$primary}" => [0],
			];

			return $query->setFilter($filter);
		}

		if ($result->isOrmConditionSupport())
		{
			if (array_key_exists('permissions', $query->getRuntimeChains() ?? []))
			{
				return $query;
			}

			$reference = new Reference(
				'ENTITY',
				$result->getEntity(),
				$result->getOrmConditions(),
				[
					'join_type' => Join::TYPE_INNER,
				],
			);

			return $query->registerRuntimeField('permissions', $reference);
		}

		$filter = $this->addRestrictionFilter(
			$query->getFilter(),
			$primary,
			$result->getSqlExpression(),
		);

		return $query->setFilter($filter);
	}

	private function buildQueryResult(
		array $permissionEntityTypes,
		QueryBuilder\QueryBuilderOptions $options,
	): QueryBuilder\Result
	{
		$cacheKey = $this->getQueryResultCacheKey($permissionEntityTypes, $options);
		if (array_key_exists($cacheKey, self::$queryResultCache))
		{
			return $this->cloneQueryResult(self::$queryResultCache[$cacheKey]);
		}

		$result = $this->createQueryBuilder($permissionEntityTypes, $options)->build();
		if (count(self::$queryResultCache) >= self::QUERY_RESULT_CACHE_LIMIT)
		{
			array_shift(self::$queryResultCache);
		}
		self::$queryResultCache[$cacheKey] = $this->cloneQueryResult($result);

		return $result;
	}

	private function cloneQueryResult(QueryBuilder\Result $result): QueryBuilder\Result
	{
		$clone = clone $result;
		if ($result->getOrmConditions() !== null)
		{
			$clone->setOrmConditions(clone $result->getOrmConditions());
		}

		return $clone;
	}

	private function getQueryResultCacheKey(
		array $permissionEntityTypes,
		QueryBuilder\QueryBuilderOptions $options,
	): string
	{
		$permissionEntityTypes = array_map('strval', $permissionEntityTypes);
		sort($permissionEntityTypes, SORT_STRING);

		$operations = array_map('strval', $options->getOperations());
		sort($operations, SORT_STRING);

		$limitByIds = $options->getLimitByIds();
		if ($limitByIds !== null)
		{
			sort($limitByIds, SORT_REGULAR);
		}

		$resultOption = $options->getResult();
		$resultOptionState = [
			'class' => $resultOption::class,
			'identityColumn' => $resultOption->getIdentityColumnName(),
		];
		foreach (
			[
				'getOrder' => 'order',
				'getLimit' => 'limit',
				'isUseDistinct' => 'useDistinct',
			] as $method => $key
		)
		{
			if (method_exists($resultOption, $method))
			{
				$resultOptionState[$key] = $resultOption->{$method}();
			}
		}

		return hash('sha256', serialize([
			'userId' => $this->userPermissions->getUserId(),
			'permissionEntityTypes' => $permissionEntityTypes,
			'operations' => $operations,
			'resultOption' => $resultOptionState,
			'aliasPrefix' => $options->getAliasPrefix(),
			'readAllAllowed' => $options->isReadAllAllowed(),
			'skipCheckOtherEntityTypes' => $options->canSkipCheckOtherEntityTypes(),
			'limitByIds' => $limitByIds,
			'useDistinctUnion' => $options->needUseDistinctUnion(),
		]));
	}

	private function addRestrictionFilter(array $filter, string $primary, $restrictExpression): array
	{
		if (empty($filter))
		{
			return ['@' . $primary => $restrictExpression];
		}

		if (array_key_exists('@' . $primary, $filter))
		{
			return [
				$filter,
				['@' . $primary => $restrictExpression]
			];
		}
		else
		{
			return array_merge(
				$filter,
				['@' . $primary => $restrictExpression]
			);
		}
	}
}
