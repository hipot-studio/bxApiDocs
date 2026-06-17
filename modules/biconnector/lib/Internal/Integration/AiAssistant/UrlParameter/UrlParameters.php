<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\UrlParameter;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardUrlParameterTable;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Parameter;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\ScopeMap;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class UrlParameters
{
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

		$urlParams = [];
		foreach ($registered as $param)
		{
			$code = $param['CODE'];
			$parameter = Parameter::tryFrom($code);
			if ($parameter === null)
			{
				continue;
			}

			if (in_array($parameter, ScopeMap::getGlobals(), true))
			{
				$urlParams[$code] = (string)ScopeMap::loadGlobalValue($parameter, $this->userId);

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
						'Invalid url parameter `' . $parameter->code() . '`: '
						. '"' . $rawOverride . '" is not a numeric id.',
						'invalid_url_parameter_format',
					));
				}
				$overrideId = (int)$rawOverride;
				if (!ScopeMap::isParameterValueAllowed($parameter, $this->userId, $overrideId))
				{
					$validValues = ScopeMap::listParameterValues($parameter, $this->userId);

					return $result->addError($this->buildInvalidUrlParamError($parameter, $overrideId, $validValues));
				}

				$urlParams[$code] = (string)$overrideId;

				continue;
			}

			return $result->addError($this->buildMissingUrlParamError($parameter));
		}

		return $result->setData(['urlParams' => $urlParams]);
	}

	/**
	 * @param array<array{id:int|string, name:string}> $validValues
	 */
	private function buildInvalidUrlParamError(
		Parameter $parameter,
		int $invalidId,
		array $validValues,
	): Error
	{
		return new Error(
			'Invalid url parameter `' . $parameter->code() . '` ('
			. $parameter->title() . '): id ' . $invalidId . ' not found or not accessible.',
			'invalid_url_parameter',
			[
				'error' => 'invalid_url_parameter',
				'paramCode' => $parameter->code(),
				'paramTitle' => $parameter->title(),
				'invalidId' => $invalidId,
				'availableValues' => $validValues,
				'total' => count($validValues),
				'instruction' => 'The supplied id does not match any entity accessible to this user. '
					. 'Show `availableValues` (id + name) to the user, ask which one to use, '
					. 'then retry with the correct urlParams value.',
			],
		);
	}

	private function buildMissingUrlParamError(Parameter $parameter): Error
	{
		$values = ScopeMap::listParameterValues($parameter, $this->userId);

		return new Error(
			'Missing required url parameter `' . $parameter->code() . '` ('
			. $parameter->title() . ').',
			'missing_url_parameter',
			[
				'error' => 'missing_url_parameter',
				'paramCode' => $parameter->code(),
				'paramTitle' => $parameter->title(),
				'availableValues' => $values,
				'total' => count($values),
				'instruction' => 'This dashboard is parameterized and cannot be loaded without a specific entity. '
					. 'Show `availableValues` (id + name) to the user, ask which one to analyze, '
					. 'then retry the tool with urlParams: {"' . $parameter->code() . '": <id>}.',
			],
		);
	}
}
