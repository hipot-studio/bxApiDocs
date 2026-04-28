<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service;

use CBPRuntime;

class EvalService
{
	public function evaluate(string $expression, ?object $context = null): void
	{
		$evaluate = function () use ($expression) {
			@eval($expression);
		};

		if (!is_null($context))
		{
			$evaluate->call($context);

			return;
		}

		$evaluate();
	}

	public function evaluateCondition(string $condition, ?object $context = null, array $variables = []): mixed
	{
		$evaluate = function () use ($condition, $variables) {
			extract($variables, EXTR_SKIP);
			$result = null;
			@eval('$result = ' . $condition . ';');

			return $result;
		};

		if (!is_null($context))
		{
			return $evaluate->call($context);
		}

		return $evaluate();
	}

	public function defineRestActivityClass(string $activityCode, int $activityId): ?string
	{
		if (!$this->isCorrectActivityCode($activityCode))
		{
			return null;
		}

		eval(
			'class CBP'
			. CBPRuntime::REST_ACTIVITY_PREFIX
			. $activityCode
			. ' extends CBPRestActivity {const REST_ACTIVITY_ID = '
			. $activityId
			. ';}'
		);

		return CBPRuntime::REST_ACTIVITY_PREFIX . $activityCode;
	}

	private function isCorrectActivityCode(string $code): bool
	{
		return !(empty($code) || preg_match("#\W#", $code));
	}
}
