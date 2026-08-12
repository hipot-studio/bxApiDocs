<?php

namespace Bitrix\BIConnector\ExternalSource\Validation;

use Bitrix\BIConnector\ExternalSource\FieldType;
use Bitrix\BIConnector\ExternalSource\Validation\Rules\Rule;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ImportDataValidator
{
	private const MAX_VALUE_LENGTH = 100;

	/** @var array<value-of<FieldType>, array<Rule>> $rulesMap */
	private array $rulesMap;
	private array $fieldsSettings;
	private bool $includeValueInCustomData;

	/**
	 * @param array $rulesMap
	 * @param array $fieldsSettings
	 * @param bool $includeValueInCustomData Whether to put the (truncated) offending value into the error
	 *     customData. The UI popup for interactive file checks needs it to show the "Value" column, but the
	 *     background log-file generation stream only reads customData['field'] and does not use it, so callers
	 *     that only render the log file should pass `false` to skip the allocation on this hot path.
	 */
	public function __construct(array $rulesMap, array $fieldsSettings, bool $includeValueInCustomData = true)
	{
		$this->rulesMap = $rulesMap;
		$this->fieldsSettings = $fieldsSettings;
		$this->includeValueInCustomData = $includeValueInCustomData;
	}

	public function validateRow(array $row): Result
	{
		$result = new Result();

		foreach ($row as $index => $value)
		{
			$fieldType = $this->fieldsSettings[$index]['TYPE'] ?? null;
			if (!$fieldType)
			{
				continue;
			}

			foreach ($this->rulesMap[$fieldType] ?? [] as $rule)
			{
				$ruleResult = $rule->validate($value);
				if (!$ruleResult->isSuccess)
				{
					$customData = ['field' => $index];
					if ($this->includeValueInCustomData)
					{
						$customData['value'] = mb_substr((string)$value, 0, self::MAX_VALUE_LENGTH);
					}

					$result->addError(new Error(
						$ruleResult->message,
						customData: $customData,
					));
				}
			}
		}

		return $result;
	}
}
