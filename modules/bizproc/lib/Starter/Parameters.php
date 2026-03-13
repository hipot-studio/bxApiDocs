<?php

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;

final class Parameters
{
	protected array $values = [];

	public function __construct(array $values)
	{
		$this->values = $values;
	}

	public function getValues(int $templateId, array $templateParameters): array
	{
		if (!array_key_exists($templateId, $this->values) || !is_array($this->values[$templateId]))
		{
			// case when not sorted by templateId values
			$this->fillValuesWithTemplateId($templateId, $templateParameters);
		}

		return $this->values[$templateId];
	}

	protected function fillValuesWithTemplateId(int $templateId, array $templateParameters): void
	{
		$parameters = [];
		foreach ($templateParameters as $originalKey => $property)
		{
			$key = $originalKey;
			if (!array_key_exists($key, $this->values))
			{
				$key = 'bizproc' . $templateId . '_' . $originalKey;
			}

			$value = $this->values[$key] ?? null;

			$parameters[$originalKey] = $value;
			unset($this->values[$key]);
		}

		$this->values[$templateId] = $parameters;
	}

	public function getValidatedValues(int $templateId, array $templateParameters, array $complexDocumentType): Result
	{
		$result = new Result();

		$values = [];
		foreach ($this->getValues($templateId, $templateParameters) as $key => $value)
		{
			$property = $templateParameters[$key] ?? ['Type' => FieldType::STRING];

			if ($property['Type'] === FieldType::FILE)
			{
				if (!empty($value) && isset($value['name']))
				{
					$values[$key] = $value;
					if (is_array($value['name']))
					{
						$values[$key] = [];
						\CFile::ConvertFilesToPost($value, $values[$key]);
					}
				}

				continue;
			}

			$values[$key] = $value;
		}

		$errors = [];
		$result->setData([
			'values' => \CBPWorkflowTemplateLoader::checkWorkflowParameters(
				$templateParameters,
				$values,
				$complexDocumentType,
				$errors
			),
		]);

		if ($errors)
		{
			$result->addErrors(array_map(static fn($error) => new Error($error['message'], $error['code']), $errors));
		}

		return $result;
	}
}
