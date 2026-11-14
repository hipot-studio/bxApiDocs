<?php

namespace Bitrix\Rest\V3\Mcp\Execution;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Exceptions\ValidationException;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ControllerBuilder;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\Engine\RestManager;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Enum\CallChannel;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\OffsetPaginatedListRequest;
use Bitrix\Rest\V3\Interaction\Request\TailRequest;
use Bitrix\Rest\V3\Interaction\Response\Response;
use Bitrix\Rest\V3\Mcp\Access\FieldRestrictionChecker;
use Bitrix\Rest\V3\Mcp\Access\ResponseFieldFilter;
use Bitrix\Rest\V3\Mcp\Access\RouteAvailabilityChecker;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Schema\SchemaManager;
use Bitrix\Rest\V3\Schema\Scope;
use Bitrix\Rest\V3\Structure\FieldsStructure;

final class RestMethodInvoker
{
	private const MCP_READ_PAGE_SIZE_CEILING = 20;
	private const MCP_READ_OFFSET_CEILING = 1000;

	public function __construct(
		private readonly ?FieldRestrictionChecker $fieldRestrictionChecker = null,
		private readonly ?RouteAvailabilityChecker $routeAvailabilityChecker = null,
		private readonly ?ResponseFieldFilter $responseFieldFilter = null,
	)
	{
	}

	public function invoke(MethodDescription $methodDescription, array $arguments, int $userId): array
	{
		$this->getRouteAvailabilityChecker()->assertRouteIsExecutable($methodDescription);
		$this->getFieldRestrictionChecker()->assertArgumentsAllowed($methodDescription, $arguments);

		$payload = $this->mergeFixedQueryParamsWithArguments($methodDescription->queryParams, $arguments);

		return $this->withAuthorizedUser($userId, function () use ($methodDescription, $payload, $userId): array {
			$this->ensureGeneratedDtoClassLoaded($methodDescription);
			$responseFieldFilter = $this->getResponseFieldFilter();
			$deniedFields = $responseFieldFilter->resolveDeniedFields($methodDescription);
			$payload = $this->applyReadDefaultsIfNeeded($methodDescription, $payload);
			$server = $this->buildInternalRestServerForUser($methodDescription, $payload, $userId);
			$controller = $this->buildRestController($methodDescription, $server, $payload);

			$manager = new RestManager();
			$autoWirings = $manager->getAutoWirings();
			$manager->registerAutoWirings($autoWirings);

			try
			{
				$response = $controller->run($methodDescription->method, [$payload, ['__restServer' => $server]]);
			}
			finally
			{
				$manager->unRegisterAutoWirings($autoWirings);
			}

			if ($controller->hasErrors())
			{
				throw $this->createControllerException($controller->getErrors()[0] ?? null);
			}

			if (!$response instanceof Response)
			{
				throw new McpException('REST V3 controller must return a Response instance.');
			}

			return $responseFieldFilter->filter($response, $deniedFields);
		});
	}

	/**
	 * The REST V3 answer names the violated fields only in its validation items, the message itself is generic.
	 */
	private function createControllerException(?Error $error): McpException|ValidationException
	{
		if ($error === null)
		{
			return new McpException('REST V3 controller returned an error.');
		}

		$customData = $error->getCustomData();
		$validationItems = is_array($customData)
			&& is_string($customData['code'] ?? null)
			&& str_starts_with($customData['code'], 'BITRIX_REST_V3_EXCEPTION_VALIDATION_')
			&& is_array($customData['validation'] ?? null)
			? $customData['validation']
			: []
		;

		$details = [];
		foreach ($validationItems as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$message = trim((string)($item['message'] ?? ''));
			if ($message === '')
			{
				continue;
			}

			$field = (string)($item['field'] ?? '');
			$details[] = $field === '' ? $message : "{$field}: {$message}";
		}

		if ($details === [])
		{
			return new McpException($error->getMessage());
		}

		// the items follow the response language of the bridge, so the summary is taken from the same output
		$summary = trim((string)($customData['message'] ?? '')) ?: $error->getMessage();

		return new ValidationException(rtrim($summary, '.') . ': ' . implode('; ', $details));
	}

	private function ensureGeneratedDtoClassLoaded(MethodDescription $methodDescription): void
	{
		ServiceLocator::getInstance()
			->get(SchemaManager::class)
			->ensureGeneratedDtoClassesLoadedFromMethodDescriptions([$methodDescription])
		;
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	private function withAuthorizedUser(int $userId, callable $callback): mixed
	{
		if ($userId <= 0)
		{
			throw new McpException('MCP REST invocation requires a positive user id.');
		}

		global $USER;

		$session = Application::getInstance()->getKernelSession();
		$previousUser = $USER;
		$hasPreviousAuth = isset($session['SESS_AUTH']);
		$previousAuth = $hasPreviousAuth ? $session['SESS_AUTH'] : null;

		// authorizing a fresh CUser keeps the previous instance intact, so it is restored as is, without Logout and its events
		$USER = new \CUser();

		try
		{
			if (!(bool)$USER->Authorize($userId, false, false))
			{
				throw new McpException('Unable to authorize MCP user for REST invocation.');
			}

			return $callback();
		}
		finally
		{
			$USER = $previousUser;
			if ($hasPreviousAuth)
			{
				$session['SESS_AUTH'] = $previousAuth;
			}
			else
			{
				unset($session['SESS_AUTH']);
			}

			unset($session['SESS_OPERATIONS']);
		}
	}

	private function mergeFixedQueryParamsWithArguments(?array $queryParams, array $arguments): array
	{
		if ($queryParams === null)
		{
			return $arguments;
		}

		$result = $queryParams;
		foreach ($arguments as $key => $value)
		{
			if ($key === 'filter')
			{
				$queryFilter = $result['filter'] ?? [];
				if (!is_array($queryFilter))
				{
					$queryFilter = [];
				}

				if (is_array($value))
				{
					$result['filter'] = $this->mergeFilterWithFixedConditions($queryFilter, $value);
				}

				continue;
			}

			if ($key === 'select' && array_key_exists('select', $queryParams))
			{
				$result['select'] = $queryParams['select'];

				continue;
			}

			if ($key === 'fields' && array_key_exists('fields', $queryParams))
			{
				// refused exactly as on the REST transport: dropping the argument would leave a write
				// action with nothing to write and report a request the agent got wrong as completed
				if (!is_array($value))
				{
					throw new InvalidRequestFieldTypeException('fields', FieldsStructure::class);
				}

				$result['fields'] = array_replace_recursive($value, $queryParams['fields']);

				continue;
			}

			// fixed route params are trusted and must not be overridden by tool arguments
			if (!array_key_exists($key, $queryParams))
			{
				$result[$key] = $value;
			}
		}

		return $result;
	}

	/**
	 * Both filters go into one AND group instead of being merged key by key: a structural filter of the agent
	 * (logic, negative, conditions) replaces the whole array otherwise, and the fixed route conditions disappear
	 * with it. The group is owned by the fixed part, so no shape of the agent filter can reach it.
	 */
	private function mergeFilterWithFixedConditions(array $fixedFilter, array $argumentFilter): array
	{
		if ($fixedFilter === [])
		{
			return $argumentFilter;
		}

		if ($argumentFilter === [])
		{
			return $fixedFilter;
		}

		return [
			'logic' => 'and',
			'conditions' => [
				...$this->splitFixedFilterIntoConditions($fixedFilter),
				...$this->splitFilterIntoConditions($argumentFilter),
			],
		];
	}

	/**
	 * A fixed AND group is unwrapped into the merged group: nested, it would hide its conditions from
	 * getSimpleFilterConditions() and #[FilterRequired], which see them over the regular transport. Any other
	 * fixed group cannot be unwrapped without changing its meaning, so the route is refused instead.
	 *
	 * @return array[]
	 */
	private function splitFixedFilterIntoConditions(array $fixedFilter): array
	{
		$isGroup =
			isset($fixedFilter['logic'])
			|| isset($fixedFilter['negative'])
			|| isset($fixedFilter['conditions'])
			|| (isset($fixedFilter['type']) && $fixedFilter['type'] !== 'condition')
		;
		if (!$isGroup)
		{
			return $this->splitFilterIntoConditions($fixedFilter);
		}

		$isAndGroup =
			($fixedFilter['type'] ?? 'filter') === 'filter'
			&& ($fixedFilter['logic'] ?? 'and') === 'and'
			&& ($fixedFilter['negative'] ?? false) === false
			&& is_array($fixedFilter['conditions'] ?? null)
		;
		if (!$isAndGroup)
		{
			throw new McpException(
				'The fixed filter of this tool is a group that cannot be combined with the tool filter.',
			);
		}

		return array_values($fixedFilter['conditions']);
	}

	/**
	 * Plain conditions keep the nesting level they were written at, so that a controller reading the request
	 * through FilterStructure::getSimpleFilterConditions() still sees them. A group keeps its own logic and goes
	 * in as a single nested filter, which fillStructure() reads only when the group declares its type.
	 *
	 * @return array[]
	 */
	private function splitFilterIntoConditions(array $filter): array
	{
		$isGroup =
			isset($filter['type'])
			|| isset($filter['logic'])
			|| isset($filter['negative'])
			|| isset($filter['conditions'])
		;
		if ($isGroup)
		{
			return [$filter + ['type' => 'filter']];
		}

		return is_array($filter[0] ?? null) && !isset($filter[0]['expression'])
			? array_values($filter)
			: [$filter]
		;
	}

	private function applyReadDefaultsIfNeeded(MethodDescription $methodDescription, array $payload): array
	{
		$requestClass = $this->resolvePrimaryRequestClass($methodDescription);
		if ($requestClass === null)
		{
			return $payload;
		}

		$dtoFieldNames = $this->getDtoFieldNames($methodDescription);

		$isLegacyListRequest = $this->requestUses($requestClass, ListRequest::class);
		$isPaginatedList = $isLegacyListRequest || (
			$methodDescription->method === 'list'
			&& $this->requestUses($requestClass, OffsetPaginatedListRequest::class)
		);
		if ($isPaginatedList)
		{
			$payload['pagination'] = $isLegacyListRequest
				? $this->normalizePagination($payload['pagination'] ?? null)
				: $this->normalizeSpecializedPagination($payload['pagination'] ?? null)
			;
		}

		if ($this->requestUses($requestClass, TailRequest::class))
		{
			$payload['cursor'] = $this->normalizeCursor($payload['cursor'] ?? null);
		}

		// Default to the identifier field when the agent omitted select.
		if (
			(
				$isPaginatedList
				|| $this->requestUses($requestClass, GetRequest::class)
				|| $this->requestUses($requestClass, TailRequest::class)
			)
			&& !array_key_exists('select', $payload)
			&& in_array('id', $dtoFieldNames, true)
		)
		{
			$defaultField = $this->resolveDefaultSelectField($methodDescription, $dtoFieldNames);
			if ($defaultField !== null)
			{
				$payload['select'] = [$defaultField];
			}
		}

		return $payload;
	}

	/**
	 * The default is applied after assertArgumentsAllowed() and is therefore checked here instead: a tool hiding
	 * the identifier would otherwise read the single field its own response filter removes, returning empty objects.
	 */
	private function resolveDefaultSelectField(MethodDescription $methodDescription, array $dtoFieldNames): ?string
	{
		$mcpTool = $methodDescription->mcpTool;
		if ($mcpTool === null || $mcpTool->isFieldAllowed('id'))
		{
			return 'id';
		}

		foreach ($dtoFieldNames as $fieldName)
		{
			if ($mcpTool->isFieldAllowed($fieldName))
			{
				return $fieldName;
			}
		}

		return null;
	}

	private function normalizePagination(mixed $pagination): array
	{
		if (!is_array($pagination))
		{
			$pagination = [];
		}

		$limit = max(1, min((int)($pagination['limit'] ?? self::MCP_READ_PAGE_SIZE_CEILING), self::MCP_READ_PAGE_SIZE_CEILING));
		$offset = (int)($pagination['offset'] ?? 0);
		if (isset($pagination['page']) && !isset($pagination['offset']))
		{
			// the page is counted in effective pages, otherwise a page smaller than the ceiling skips records
			$offset = (max(1, (int)$pagination['page']) - 1) * $limit;
		}

		// a silently clamped offset returns the same page forever, so the caller is told to change the query instead
		if ($offset > self::MCP_READ_OFFSET_CEILING)
		{
			throw new McpException(sprintf(
				'Paging is limited to offset %d, requested %d. Narrow the filter or read further pages with a cursor.',
				self::MCP_READ_OFFSET_CEILING,
				$offset,
			));
		}

		return [
			'limit' => $limit,
			'offset' => max(0, $offset),
		];
	}

	private function normalizeSpecializedPagination(mixed $pagination): mixed
	{
		if ($pagination === null)
		{
			return ['limit' => self::MCP_READ_PAGE_SIZE_CEILING, 'offset' => 0];
		}

		if (!is_array($pagination))
		{
			return $pagination;
		}

		if (!array_key_exists('limit', $pagination))
		{
			$pagination['limit'] = self::MCP_READ_PAGE_SIZE_CEILING;
		}
		elseif (is_numeric($pagination['limit']) && $pagination['limit'] > self::MCP_READ_PAGE_SIZE_CEILING)
		{
			$pagination['limit'] = self::MCP_READ_PAGE_SIZE_CEILING;
		}

		if (!array_key_exists('offset', $pagination))
		{
			$pagination['offset'] = 0;
		}
		elseif (is_numeric($pagination['offset']) && $pagination['offset'] > self::MCP_READ_OFFSET_CEILING)
		{
			$this->throwOffsetLimitException($pagination['offset']);
		}

		if (
			isset($pagination['page'])
			&& is_numeric($pagination['page'])
			&& isset($pagination['limit'])
			&& is_numeric($pagination['limit'])
			&& $pagination['limit'] > 0
		)
		{
			$offset = (int)($pagination['page'] > 0 ? $pagination['page'] - 1 : 0)
				* (int)$pagination['limit'];
			if ($offset > self::MCP_READ_OFFSET_CEILING)
			{
				$this->throwOffsetLimitException($offset);
			}
		}

		return $pagination;
	}

	private function throwOffsetLimitException(int|float|string $offset): never
	{
		throw new McpException(sprintf(
			'Paging is limited to offset %d, requested %s. Narrow the filter or read further pages with a cursor.',
			self::MCP_READ_OFFSET_CEILING,
			(string)$offset,
		));
	}

	private function normalizeCursor(mixed $cursor): array
	{
		if (!is_array($cursor))
		{
			$cursor = [];
		}

		// only the page size is capped: CursorStructure requires field and value, and order drives the direction
		$limit = (int)($cursor['limit'] ?? self::MCP_READ_PAGE_SIZE_CEILING);
		$normalized = $cursor;
		$normalized['limit'] = max(1, min($limit, self::MCP_READ_PAGE_SIZE_CEILING));

		return $normalized;
	}

	private function requestUses(string $requestClass, string $baseRequestClass): bool
	{
		return $requestClass === $baseRequestClass || is_subclass_of($requestClass, $baseRequestClass);
	}

	private function resolvePrimaryRequestClass(MethodDescription $methodDescription): ?string
	{
		if ($methodDescription->controllerFqcn === null || !class_exists($methodDescription->controllerFqcn))
		{
			return null;
		}

		$actionMethodName = $methodDescription->method . 'Action';
		$controllerReflection = new \ReflectionClass($methodDescription->controllerFqcn);
		if (!$controllerReflection->hasMethod($actionMethodName))
		{
			return null;
		}

		foreach ($controllerReflection->getMethod($actionMethodName)->getParameters() as $parameter)
		{
			$type = $parameter->getType();
			if (!$type instanceof \ReflectionNamedType || $type->isBuiltin())
			{
				continue;
			}

			$typeName = $type->getName();
			if (is_subclass_of($typeName, \Bitrix\Rest\V3\Interaction\Request\Request::class))
			{
				return $typeName;
			}
		}

		return null;
	}

	/**
	 * The vocabulary the method itself speaks, taken from its own dtoFqcn: for a field dictionary these are the
	 * attributes of DtoFieldDto, not the fields of the entity it describes (queryParams['dtoClass']).
	 */
	private function getDtoFieldNames(MethodDescription $methodDescription): array
	{
		$dtoClass = $methodDescription->dtoFqcn;
		if (!TypeHelper::isDto($dtoClass))
		{
			return [];
		}

		$dto = $dtoClass::create();
		$fieldNames = [];
		foreach ($dto->getFields()->getMetaList() as $field)
		{
			$fieldNames[] = $field->getPropertyName();
		}

		return $fieldNames;
	}

	private function buildRestController(
		MethodDescription $methodDescription,
		\CRestApiServer $server,
		array $payload,
	): RestController
	{
		$httpRequest = new InProcessHttpRequest(
			Context::getCurrent()->getServer(),
			[],
			[],
			[],
			[],
			$payload,
		);

		$controller = ControllerBuilder::build($methodDescription->controllerFqcn, [
			'scope' => Controller::SCOPE_REST,
			// the global user is already authorized by withAuthorizedUser(), so both identities stay consistent
			'currentUser' => CurrentUser::get(),
			'request' => $httpRequest,
		]);

		if (!$controller instanceof RestController)
		{
			throw new McpException('REST V3 MCP bridge supports only RestController descendants.');
		}

		$controller
			->setDtoClass($methodDescription->dtoFqcn)
			->setMethodDescription($methodDescription)
			->setProcessedScope($this->resolveSpecificScopeWithoutFieldRestriction($methodDescription))
			->setServer($server)
			->setQueryParams($payload)
			->setCallChannel(CallChannel::Mcp)
		;
		$controller->setResponseLanguage('en');

		return $controller;
	}

	private function resolveSpecificScopeWithoutFieldRestriction(MethodDescription $methodDescription): Scope
	{
		$bestScope = null;
		foreach ($methodDescription->scopes as $scope)
		{
			if ($bestScope === null || substr_count($scope, '.') > substr_count($bestScope, '.'))
			{
				$bestScope = $scope;
			}
		}

		return new Scope($bestScope ?? 'rest');
	}

	private function getFieldRestrictionChecker(): FieldRestrictionChecker
	{
		return $this->fieldRestrictionChecker ?? new FieldRestrictionChecker();
	}

	private function getRouteAvailabilityChecker(): RouteAvailabilityChecker
	{
		return $this->routeAvailabilityChecker ?? new RouteAvailabilityChecker();
	}

	private function getResponseFieldFilter(): ResponseFieldFilter
	{
		return $this->responseFieldFilter ?? new ResponseFieldFilter();
	}

	private function buildInternalRestServerForUser(
		MethodDescription $methodDescription,
		array $payload,
		int $userId,
	): \CRestApiServer
	{
		$params = [
			'METHOD' => $methodDescription->actionUri,
			'QUERY' => $payload,
			'RESPONSE_LANGUAGE' => 'en',
		];

		return \CRestApiServer::createForInternalCall($params, $userId, 'mcp');
	}
}
