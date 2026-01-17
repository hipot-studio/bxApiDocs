<?php

namespace Bitrix\Bizproc\Activity\Mixins;

trait ActivityFilterChecker
{
	public function checkActivityFilter(array $filter, ?array $documentType = null): bool
	{
		$distributiveName = \CBPHelper::getDistrName();
		foreach ($filter as $type => $rules)
		{
			if ($type === 'MIN_API_VERSION')
			{
				$minApiVersion = (int)$rules;
				if ($minApiVersion > \CBPRuntime::ACTIVITY_API_VERSION)
				{
					return false;
				}

				continue;
			}

			$found = $this->checkActivityFilterRules($rules, $documentType, $distributiveName);
			if (($type === 'INCLUDE' && !$found) || ($type === 'EXCLUDE' && $found))
			{
				return false;
			}
		}

		return true;
	}

	private function checkActivityFilterRules(mixed $rules, ?array $documentType, string $distributiveName): bool
	{
		if (!is_array($rules) || \CBPHelper::isAssociativeArray($rules))
		{
			$rules = [$rules];
		}

		foreach ($rules as $rule)
		{
			$result = false;
			if (is_array($rule))
			{
				if (!$documentType)
				{
					$result = true;
				}
				else
				{
					foreach ($documentType as $key => $value)
					{
						if (!isset($rule[$key]))
						{
							break;
						}
						$result = $rule[$key] === $value;
						if (!$result)
						{
							break;
						}
					}
				}
			}
			else
			{
				$result = (string)$rule === $distributiveName;
			}

			if ($result)
			{
				return true;
			}
		}

		return false;
	}
}
