<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class AgentConnectionsValidator
{
	private AgentConnectionCollection $validConnections;

	public function __construct()
	{
		$this->validConnections = new AgentConnectionCollection();
	}

	public function validate(mixed $connections, array $blockIds = [], string $path = ''): Result
	{
		$this->validConnections = new AgentConnectionCollection();
		if (!is_array($connections))
		{
			return (new Result())->addError(new Error("{$path} should be array"));
		}

		if (empty($connections) && count($blockIds) > 1)
		{
			return (new Result())->addError(new Error("{$path} should be not empty array"));
		}

		$result = new Result();
		foreach ($connections as $key => $connection)
		{
			$connectionValidateResult = $this->validateConnection(
				connection: $connection,
				path: "{$path}.{$key}",
				blockIds: $blockIds,
			);
			$result->addErrors($connectionValidateResult->getErrors());
		}

		return $result;
	}

	private function validateConnection(mixed $connection, string $path, array $blockIds): Result
	{
		if (!is_array($connection))
		{
			return (new Result())->addError(new Error("$path should be object"));
		}

		$result = new Result();

		$destinationBlockId = $connection['destinationBlockId'] ?? null;
		$destinationValidateResult = $this->validateConnectionBlockId(
			id: $destinationBlockId,
			path: "{$path}.destinationBlockId",
			blockIds: $blockIds,
		);
		$result->addErrors($destinationValidateResult->getErrors());

		$sourceBlockId = $connection['sourceBlockId'] ?? null;
		$sourceValidateResult = $this->validateConnectionBlockId(
			id: $sourceBlockId,
			path: "{$path}.sourceBlockId",
			blockIds: $blockIds
		);
		$result->addErrors($sourceValidateResult->getErrors());

		if ($result->isSuccess())
		{
			$this->validConnections->add(
				new AgentConnection(
					destinationBlockId: $destinationBlockId,
					sourceBlockId: $sourceBlockId,
				)
			);
		}

		return $result;
	}

	private function validateConnectionBlockId(mixed $id, string $path, array $blockIds): Result
	{
		if (!is_string($id) || $id === '')
		{
			return (new Result())->addError(new Error("$path should be not empty string"));
		}

		if (!in_array($id, $blockIds, true))
		{
			return (new Result())->addError(new Error("$path is incorrect block id"));
		}

		return new Result();
	}

	public function getValidConnections(): AgentConnectionCollection
	{
		return $this->validConnections;
	}
}