<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Helper;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

class JsonHelper
{
	/**
	 * Builds JSON path from JSON schema.
	 * elements must contain "properties" key.
	 * For example:
	 * {
	 *  "type": "object",
	 *  "properties": {
	 *     "name": { "type": "string" },
	 *     "address": {
	 *        "type": "object",
	 *         "properties": {
	 *             "street": { "type": "string" },
	 *             "city": { "type": "string" }
	 *         }
	 *     }
	 *   }
	 * }
	 *
	 * @param string $jsonSchema
	 * @param string $activityName
	 *
	 * @return array|null
	 */
	public static function buildJsonPath(string $jsonSchema, string $activityName): ?array
	{
		$jsonSchema = trim($jsonSchema);
		if ($jsonSchema === '')
		{
			return null;
		}

		try
		{
			$properties = Json::decode($jsonSchema);
		}
		catch (\Throwable)
		{
			return null;
		}

		return self::extractProperties($properties, $activityName);
	}

	/**
	 * @param mixed $element
	 * @param string $activityName
	 * @param string $parentPath
	 *
	 * @return array
	 */
	private static function extractProperties(
		mixed $element,
		string $activityName,
		string $parentPath = '',
	): array
	{
		if (!is_array($element) || !isset($element['properties']))
		{
			return [];
		}

		$result = [];
		foreach ($element['properties'] as $key => $value)
		{
			$fullKey = $parentPath === '' ? $key : "$parentPath.$key";

			if (is_array($value) && isset($value['properties']))
			{
				$result += self::extractProperties($value, $activityName, $fullKey);
			}
			else
			{
				$result[$fullKey] = self::normalizeProperty($fullKey, $activityName);
			}
		}

		return $result;
	}

	/**
	 * @param string $fullKey
	 * @param string $activityName
	 *
	 * @return array
	 */
	private static function normalizeProperty(string $fullKey, string $activityName): array
	{
		return FieldType::normalizeProperty(
			[
				'Id' => $fullKey,
				'Name' => 'JSON:' . $fullKey,
				'Type' => FieldType::JSON,
			],
		);
	}

	/**
	 * Validates JSON schema.
	 *
	 * @param string|null $rawSchema
	 * @param string $paramName
	 * @param array<int,array{code:string,parameter:string,message:string}> $errors
	 *
	 * @return void
	 */
	public static function validateJsonSchema(?string $rawSchema, string $paramName, array &$errors): void
	{
		$rawSchema = trim((string)$rawSchema);
		if ($rawSchema === '')
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => $paramName,
				'message' => Loc::getMessage('AI_PROCESSING_ACTIVITY_JSON_SCHEMA_REQUIRED'),
			];

			return;
		}

		try
		{
			$decoded = Json::decode($rawSchema);
			if (!is_array($decoded) && !is_object($decoded))
			{
				$errors[] = [
					'code' => 'Invalid',
					'parameter' => $paramName,
					'message' => Loc::getMessage('AI_PROCESSING_ACTIVITY_JSON_SCHEMA_INVALID'),
				];
			}
		}
		catch (\Throwable)
		{
			$errors[] = [
				'code' => 'Invalid',
				'parameter' => $paramName,
				'message' => Loc::getMessage('AI_PROCESSING_ACTIVITY_JSON_SCHEMA_INVALID'),
			];
		}
	}

	/**
	 * Processes raw result based on return type.
	 * @param string $raw
	 * @param string $returnType
	 *
	 * @return string
	 */
	public static function processResult(string $raw, string $returnType): string
	{
		if ($returnType === \CBPAiProcessingActivity::RETURN_TYPE_JSON)
		{
			try
			{
				$decoded = Json::decode($raw);

				return Json::encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			}
			catch (\Throwable)
			{
			}
		}

		return $raw;
	}


	public static function extractValueByPath(array $path, string $json)
	{
		try
		{
			$data = Json::decode($json);
		}
		catch (\Exception)
		{
			return null;
		}
		if (!is_array($data))
		{
			return null;
		}

		$value = $data;
		foreach ($path as $key)
		{
			if (is_array($value) && array_key_exists($key, $value))
			{
				$value = $value[$key];
			}
			else
			{
				return null;
			}
		}

		return $value;
	}
}
