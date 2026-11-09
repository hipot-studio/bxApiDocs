<?php

namespace Bitrix\Superset\Internal\Support;

use Bitrix\Main;
use Bitrix\Superset\Internal\HttpStatus;
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
		$refusal = $this->createActAsRefusalResult($requestResult);
		if ($refusal !== null)
		{
			return $refusal;
		}

		return $this->resultFactory->createRequestErrorResult($requestResult, $fallbackMessage);
	}

	/**
	 * @return SupersetInstance connector bound to the user, or the plain one when no user is named
	 */
	protected function getConnectorFor(?string $actAsLogin): SupersetInstance
	{
		return $actAsLogin === null ? $this->connector : $this->connector->withActAs($actAsLogin);
	}

	protected function createInvalidActAsLoginResult(): Main\Result
	{
		$result = new Main\Result();
		$result->addError(new Main\Error('Empty user login for act-as', ActAs::ERROR_INVALID_LOGIN));
		$result->setData(['httpStatus' => HttpStatus::BAD_REQUEST]);

		return $result;
	}

	/**
	 * Keeps the act-as refusal of the fork under its own code instead of the bare HTTP status,
	 * so the consumer can tell "this user may not be acted as" from an outage. The same holds for the refusal
	 * of the permissions on tables to the person acted as.
	 */
	protected function createActAsRefusalResult(RequestResult $requestResult): ?Main\Result
	{
		$body = $this->decode($requestResult->getAnswer());
		$refusal = ActAs::findForkRefusal($body);
		if ($refusal === null)
		{
			if (!ActAs::isTableAccessDenied($body))
			{
				return null;
			}

			$result = new Main\Result();
			$result->addError(new Main\Error(
				'The permissions on tables close what the request reads',
				ActAs::TABLE_ACCESS_DENIED,
			));
			$result->setData(['httpStatus' => $requestResult->getHttpStatus(), 'requestResult' => $requestResult]);

			return $result;
		}

		$result = new Main\Result();
		$result->addError(new Main\Error('Superset refused to act as the requested user', $refusal));
		$result->setData(['httpStatus' => $requestResult->getHttpStatus(), 'requestResult' => $requestResult]);

		return $result;
	}

	/**
	 * @return Main\Result|null error when the request ran as another user than asked, null when it is fine
	 */
	protected function checkActAsApplied(RequestResult $requestResult, ?string $actAsLogin): ?Main\Result
	{
		if ($actAsLogin === null || ActAs::isApplied($requestResult, $actAsLogin))
		{
			return null;
		}

		$result = new Main\Result();
		$result->addError(new Main\Error(
			'Superset did not confirm the act-as substitution',
			ActAs::ERROR_UNSUPPORTED,
		));
		// A client-range status: a 5xx would be read by the portal as an outage of the whole instance.
		$result->setData(['httpStatus' => HttpStatus::UNPROCESSABLE_ENTITY]);

		return $result;
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
