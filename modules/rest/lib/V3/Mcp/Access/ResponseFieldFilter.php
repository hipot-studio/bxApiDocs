<?php

namespace Bitrix\Rest\V3\Mcp\Access;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\AggregateResponse;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Interaction\Response\Response;
use Bitrix\Rest\V3\Interaction\Response\ResponseWithRelations;
use Bitrix\Rest\V3\Schema\MethodDescription;

/**
 * The published schema and the argument check keep restricted fields out of the request, but a response may still
 * carry them: the default select covers read requests only, a manual add or update returns the whole entity, and
 * an aggregate or a response of its own shape names fields in its keys.
 *
 * The denied fields are resolved before the controller runs, so a restriction that cannot be applied stops the call
 * before it changes anything; the response is filtered after it.
 */
final class ResponseFieldFilter
{
	// the only envelope keys carrying entity data: GetResponse::$item and ListResponse::$items
	private const ENTITY_PAYLOAD_KEYS = ['item', 'items'];

	/**
	 * @return array<string, true>
	 * @throws McpException when the field whitelist of the tool cannot be resolved against the DTO of its method
	 */
	public function resolveDeniedFields(MethodDescription $methodDescription): array
	{
		$mcpTool = $methodDescription->mcpTool;
		if ($mcpTool === null || !$mcpTool->hasFieldRestrictions())
		{
			return [];
		}

		$dtoClass = $methodDescription->dtoFqcn;
		$isDtoResolvable = TypeHelper::isDto($dtoClass);
		if ($mcpTool->fieldWhitelist !== null && !$isDtoResolvable)
		{
			throw new McpException(
				'Field restrictions of this tool cannot be applied: '
				. 'its method has no DTO to resolve the field whitelist.'
			);
		}

		$deniedFields = [];
		if ($isDtoResolvable)
		{
			foreach ($dtoClass::create()->getFields()->getMetaList() as $field)
			{
				$fieldName = $field->getPropertyName();
				if (!$mcpTool->isFieldAllowed($fieldName))
				{
					$deniedFields[$fieldName] = true;
				}
			}
		}

		foreach ($mcpTool->hiddenFields as $fieldName)
		{
			$deniedFields[$fieldName] = true;
		}

		return $deniedFields;
	}

	/**
	 * @param array<string, true> $deniedFields
	 */
	public function filter(Response $response, array $deniedFields): array
	{
		$result = $response->toArray();
		if ($deniedFields === [])
		{
			return $result;
		}

		if ($response instanceof ResponseWithRelations)
		{
			return $this->removeDeniedFieldsFromEntityPayload($result, $deniedFields);
		}

		// an id of the created record and a result flag are envelope keys, not fields of the entity
		if ($response instanceof AddResponse || $response instanceof BooleanResponse)
		{
			return $result;
		}

		if ($response instanceof AggregateResponse)
		{
			return $this->removeDeniedFieldsFromAggregateResult($result, $deniedFields);
		}

		// the shape is unknown, so a denied name is removed wherever it is used as a key
		return $this->removeDeniedFields($result, $deniedFields);
	}

	/**
	 * Only the entity payload of the envelope is walked: an entity field named like an envelope key must not take
	 * the envelope down with it.
	 *
	 * @param array<string, true> $deniedFields
	 */
	private function removeDeniedFieldsFromEntityPayload(array $result, array $deniedFields): array
	{
		foreach (self::ENTITY_PAYLOAD_KEYS as $payloadKey)
		{
			$payload = $result[$payloadKey] ?? null;
			if (is_object($payload))
			{
				$payload = $this->toPlainValue($payload);
			}

			if (is_array($payload))
			{
				$result[$payloadKey] = $this->removeDeniedFields($payload, $deniedFields);
			}
		}

		return $result;
	}

	/**
	 * The result is {function: {field: value}}: a field may be named like a function, so only the second level
	 * is cleaned and the buckets of the functions stay.
	 *
	 * @param array<string, true> $deniedFields
	 */
	private function removeDeniedFieldsFromAggregateResult(array $result, array $deniedFields): array
	{
		if (!isset($result['result']) || !is_array($result['result']))
		{
			return $result;
		}

		foreach ($result['result'] as $function => $values)
		{
			if (is_array($values))
			{
				$result['result'][$function] = array_diff_key($values, $deniedFields);
			}
		}

		return $result;
	}

	/**
	 * @param array<string, true> $deniedFields
	 */
	private function removeDeniedFields(array $data, array $deniedFields): array
	{
		foreach ($data as $key => $value)
		{
			if (is_string($key) && isset($deniedFields[$key]))
			{
				unset($data[$key]);

				continue;
			}

			if (is_object($value))
			{
				$value = $this->toPlainValue($value);
				$data[$key] = $value;
			}

			if (is_array($value))
			{
				$data[$key] = $this->removeDeniedFields($value, $deniedFields);
			}
		}

		return $data;
	}

	/**
	 * toArray() of a response turns only its first level into arrays: an object below it would reach the encoder
	 * whole, denied fields included. It becomes an array the way the first level does, or the way the encoder
	 * would see it.
	 */
	private function toPlainValue(object $value): mixed
	{
		if ($value instanceof Arrayable)
		{
			return $value->toArray();
		}

		return Json::decode(Json::encode($value));
	}
}
