<?php

namespace Bitrix\Rest\V3\Mcp\Schema;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Structure\Aggregation\AggregationSelectStructure;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Tells whether the select argument of a route is an aggregation select, a map of functions to field lists.
 *
 * The REST transport reads it so when the request declares select as AggregationSelectStructure. A module may also
 * take it as a plain array on its aggregate action and parse it by itself, so the aggregate verb counts as well.
 */
final class AggregateSelectResolver
{
	private const AGGREGATE_METHOD = 'aggregate';
	private const SELECT_PROPERTY = 'select';

	public function isAggregateSelect(MethodDescription $methodDescription): bool
	{
		if (strtolower($methodDescription->method) === self::AGGREGATE_METHOD)
		{
			return true;
		}

		$requestClass = $this->resolveActionRequestClass($methodDescription);

		return $requestClass !== null && $this->declaresAggregationSelect($requestClass);
	}

	/**
	 * @return class-string<Request>|null
	 */
	private function resolveActionRequestClass(MethodDescription $methodDescription): ?string
	{
		$controllerFqcn = $methodDescription->controllerFqcn;
		if ($controllerFqcn === null || !class_exists($controllerFqcn))
		{
			return null;
		}

		$actionMethodName = $methodDescription->method . 'Action';
		$controllerReflection = new ReflectionClass($controllerFqcn);
		if (!$controllerReflection->hasMethod($actionMethodName))
		{
			return null;
		}

		foreach ($controllerReflection->getMethod($actionMethodName)->getParameters() as $parameter)
		{
			$parameterType = $parameter->getType();
			if (
				$parameterType instanceof ReflectionNamedType
				&& !$parameterType->isBuiltin()
				&& is_subclass_of($parameterType->getName(), Request::class)
			)
			{
				return $parameterType->getName();
			}
		}

		return null;
	}

	/**
	 * @param class-string<Request> $requestClass
	 */
	private function declaresAggregationSelect(string $requestClass): bool
	{
		$requestReflection = new ReflectionClass($requestClass);
		if (!$requestReflection->hasProperty(self::SELECT_PROPERTY))
		{
			return false;
		}

		$selectProperty = $requestReflection->getProperty(self::SELECT_PROPERTY);
		$selectType = $selectProperty->getType();

		return
			$selectProperty->isPublic()
			&& $selectType instanceof ReflectionNamedType
			&& is_a($selectType->getName(), AggregationSelectStructure::class, true)
		;
	}
}
