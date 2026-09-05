<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardUrlParameterTable;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\ScopeMap;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class UrlParameters
{
	private const SCOPE_VALUE_LIMIT = 50;

	public function __construct(private readonly int $userId)
	{
	}

	/**
	 * Resolve url parameters for the dashboard. On success,
	 * `getData()['urlParams']` holds `array<string, string>` ready for the
	 * query string.
	 */
	public function resolve(int $dashboardId, array $overrides): Result
	{
		$result = new Result();

		$registered = SupersetDashboardUrlParameterTable::getList([
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
			'select' => ['CODE'],
		])->fetchAll();

		$urlParams = $this->resolveGlobals();
		foreach ($registered as $param)
		{
			$code = $param['CODE'];
			$parameter = Parameter::tryFrom($code);
			if ($parameter === null || isset($urlParams[$code]))
			{
				continue;
			}

			if (isset($overrides[$code]) && $overrides[$code] !== '' && $overrides[$code] !== null)
			{
				$rawOverride = $overrides[$code];
				// `(int)$array` silently yields 1, hiding caller mistakes behind
				// a possibly-valid id. Require scalar input explicitly.
				if (!is_int($rawOverride) && !is_string($rawOverride))
				{
					return $result->addError(new Error(
						'Invalid url parameter `' . $parameter->code() . '`: expected an integer id, '
						. 'got ' . get_debug_type($rawOverride) . '. Pass a single numeric id, '
						. 'not a list or object.',
						'invalid_url_parameter_type',
					));
				}
				if (is_string($rawOverride) && !preg_match('/^-?\d+$/', $rawOverride))
				{
					return $result->addError(new Error(
						"Invalid url parameter `{$parameter->code()}`: \"{$rawOverride}\" is not a numeric id.",
						'invalid_url_parameter_format',
					));
				}
				$overrideId = (int)$rawOverride;
				if (!ScopeMap::isParameterValueAllowed($parameter, $this->userId, $overrideId))
				{
					return $result->addError($this->buildInvalidUrlParamError($parameter, $overrideId));
				}

				$urlParams[$code] = (string)$overrideId;

				continue;
			}

			return $result->addError($this->buildMissingUrlParamError($parameter));
		}

		return $result->setData(['urlParams' => $urlParams]);
	}

	/**
	 * Global parameters go in regardless of registration. They are registered
	 * when a report is created or saved through the edit form, but there is no
	 * migration for reports created earlier, and market reports get them only
	 * with a scope in the manifest. Without `current_user` Superset cannot tell
	 * on whose behalf it counts: the org-structure filter loses its default and
	 * the system filter datasets fall back to the whole portal.
	 *
	 * @return array<string, string>
	 */
	private function resolveGlobals(): array
	{
		$urlParams = [];
		foreach (ScopeMap::getGlobals() as $parameter)
		{
			$urlParams[$parameter->code()] = (string)ScopeMap::loadGlobalValue($parameter, $this->userId);
		}

		return $urlParams;
	}

	/**
	 * Describe the non-global url parameters a dashboard requires, without
	 * erroring — the preflight counterpart of {@see resolve()}. Globals
	 * (auto-injected server-side, e.g. current_user) are omitted: the caller
	 * never supplies them.
	 *
	 * On success `getData()['parameters']` holds
	 * `array<array{paramCode:string, paramTitle:string, availableValues:array, offset:int, hasMore:bool}>`.
	 * Optional substring search narrows each parameter's availableValues by name;
	 * `$offset` pages through a long list (page size {@see self::SCOPE_VALUE_LIMIT}).
	 */
	public function describe(int $dashboardId, ?string $search = null, int $offset = 0): Result
	{
		$result = new Result();

		$registered = SupersetDashboardUrlParameterTable::getList([
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
			'select' => ['CODE'],
		])->fetchAll();

		$parameters = [];
		foreach ($registered as $param)
		{
			if (!self::isScopeParameterCode($param['CODE']))
			{
				continue;
			}
			$parameter = Parameter::from($param['CODE']);

			[$values, $hasMore] = $this->getScopeValues($parameter, $search, $offset);
			$parameters[] = [
				'paramCode' => $parameter->code(),
				'paramTitle' => $parameter->title(),
				'availableValues' => $values,
				'offset' => $offset,
				'hasMore' => $hasMore,
			];
		}

		return $result->setData(['parameters' => $parameters]);
	}

	/**
	 * Which of the given dashboards are parameterized — scoped to a non-global
	 * entity that must be chosen before the report can load. User-independent
	 * structural check; the actual values come from {@see describe()}.
	 *
	 * @param int[] $dashboardIds
	 * @return array<int, bool> dashboardId => requiresScope
	 */
	public static function requiresScopeBatch(array $dashboardIds): array
	{
		$result = array_fill_keys(array_map('intval', $dashboardIds), false);
		if (empty($result))
		{
			return [];
		}

		$registered = SupersetDashboardUrlParameterTable::getList([
			'filter' => ['=DASHBOARD_ID' => array_keys($result)],
			'select' => ['DASHBOARD_ID', 'CODE'],
		])->fetchAll();

		foreach ($registered as $param)
		{
			if (self::isScopeParameterCode($param['CODE']))
			{
				$result[(int)$param['DASHBOARD_ID']] = true;
			}
		}

		return $result;
	}

	private static function isScopeParameterCode(string $code): bool
	{
		$parameter = Parameter::tryFrom($code);

		return $parameter !== null && !in_array($parameter, ScopeMap::getGlobals(), true);
	}

	private function getScopeValues(
		Parameter $parameter,
		?string $search = null,
		int $offset = 0
	): array
	{
		$values = ScopeMap::listParameterValues(
			$parameter,
			$this->userId,
			$search,
			self::SCOPE_VALUE_LIMIT + 1,
			$offset,
		);

		$hasMore = count($values) > self::SCOPE_VALUE_LIMIT;

		return [array_slice($values, 0, self::SCOPE_VALUE_LIMIT), $hasMore];
	}

	private function buildInvalidUrlParamError(Parameter $parameter, int $invalidId): Error
	{
		[$values, $hasMore] = $this->getScopeValues($parameter);

		return new Error(
			"Invalid url parameter `{$parameter->code()}` ({$parameter->title()}): id {$invalidId} not found or not accessible.",
			'invalid_url_parameter',
			[
				'error' => 'invalid_url_parameter',
				'paramCode' => $parameter->code(),
				'paramTitle' => $parameter->title(),
				'invalidId' => $invalidId,
				'availableValues' => $values,
				'hasMore' => $hasMore,
				'instruction' => 'The supplied id does not match any entity accessible to this user. '
					. 'Show `availableValues` (id + name) to the user, ask which one to use, '
					. 'then retry with the correct urlParams value. If `hasMore` is true, narrow the '
					. 'list with search_dashboard_parameters({search}).',
			],
		);
	}

	private function buildMissingUrlParamError(Parameter $parameter): Error
	{
		[$values, $hasMore] = $this->getScopeValues($parameter);

		return new Error(
			"Missing required url parameter `{$parameter->code()}` ({$parameter->title()}).",
			'missing_url_parameter',
			[
				'error' => 'missing_url_parameter',
				'paramCode' => $parameter->code(),
				'paramTitle' => $parameter->title(),
				'availableValues' => $values,
				'hasMore' => $hasMore,
				'instruction' => 'This dashboard is parameterized and cannot be loaded without a specific entity. '
					. 'Show `availableValues` (id + name) to the user, ask which one to analyze, '
					. 'then retry the tool with urlParams: {"' . $parameter->code() . '": <id>}. '
					. 'If `hasMore` is true, narrow the list with search_dashboard_parameters({search}).',
			],
		);
	}
}
