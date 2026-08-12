<?php

namespace Bitrix\Superset\Internal\Support;

use Bitrix\Main;
use Bitrix\Superset\Internal\RequestResult;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Services\UserMapper;

abstract class AbstractSupersetContext
{
	protected Server $server;
	protected SupersetInstance $connector;

	private readonly SupersetResultFactory $resultFactory;

	public function __construct(Server $server, ?SupersetInstance $connector = null)
	{
		$this->server = $server;
		$this->connector = $connector ?? new SupersetInstance($server);
		$this->resultFactory = new SupersetResultFactory();
	}

	protected function decode(string $data): ?array
	{
		try
		{
			return Main\Web\Json::decode($data);
		}
		catch (Main\ArgumentException)
		{
			return null;
		}
	}

	protected function createErrorResult(
		string $message,
		?RequestResult $requestResult = null,
		?int $httpStatus = null
	): Main\Result
	{
		return $this->resultFactory->createErrorResult($message, $requestResult, $httpStatus);
	}

	protected function createRequestErrorResult(
		RequestResult $requestResult,
		string $fallbackMessage
	): Main\Result
	{
		return $this->resultFactory->createRequestErrorResult($requestResult, $fallbackMessage);
	}

	protected function mapUsersToClientIds(array $elements): array
	{
		return UserMapper::mapUsersToClientIds($elements, $this->server);
	}

	protected function getResultFactory(): SupersetResultFactory
	{
		return $this->resultFactory;
	}
}
