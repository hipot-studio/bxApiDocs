<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Service\Otp;

use Bitrix\Intranet\Internal\Entity\Otp\MessengerConfirmationState;
use Bitrix\Intranet\Internal\Integration\Pull\Otp\MessengerConfirmationSocketConfig;
use Bitrix\Intranet\Internal\Integration\Security\DeferredLoginAttempt;
use Bitrix\Intranet\Internal\Service\Otp\MessengerConfirmationCoordinator;
use Bitrix\Intranet\Internal\Service\Otp\MessengerConfirmationSessionBinding;
use Bitrix\Intranet\Public\Dto\Otp\MessengerConfirmation\Request;
use Bitrix\Intranet\Public\Dto\Otp\MessengerConfirmation\Status;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;

/**
 * The messenger confirmation contract of the login form, one description for every transport.
 * Data of a successful Result is the response body as released clients read it; a transport owns
 * the kernel session key its login attempt is bound with and, for a browser, the raw pull config
 * next to socketConfig.
 */
final class MessengerConfirmationEndpoint
{
	private const COORDINATOR_SERVICE = 'intranet.service.otp.messenger_confirmation';

	private readonly MessengerConfirmationSessionBinding $sessionBinding;
	private readonly DeferredLoginAttempt $loginAttempt;

	public function __construct(string $sessionKey)
	{
		$this->sessionBinding = new MessengerConfirmationSessionBinding($sessionKey);
		$this->loginAttempt = new DeferredLoginAttempt();
	}

	public function createRequest(bool $withPullConfig = false): Result
	{
		$result = new Result();
		$coordinator = $this->getCoordinator($result);
		if ($coordinator === null)
		{
			return $result;
		}

		$userId = $this->getUserId();
		$request = $this->extract(
			$coordinator->create(
				$userId,
				$userId > 0 ? $this->sessionBinding->issue($userId, $this->loginAttempt->claimId()) : '',
			),
			MessengerConfirmationCoordinator::DATA_REQUEST,
			Request::class,
			$result,
		);

		if (!($request instanceof Request))
		{
			return $result;
		}

		$response = [
			'requestId' => $request->requestId,
			'status' => $request->status->value,
			'ttl' => $request->getTtl(time()),
			'expiresAt' => $request->expiresAt,
			'resendAvailableAt' => $request->resendAvailableAt,
			'pollAfter' => $request->pollAfter,
			'socketConfig' => MessengerConfirmationSocketConfig::build($request->connectionConfig),
		];

		if ($withPullConfig)
		{
			$response['pullConfig'] = $request->connectionConfig ?: null;
		}

		return $result->setData($response);
	}

	public function readStatus(string $requestId): Result
	{
		$result = new Result();
		$coordinator = $this->getCoordinator($result);
		if ($coordinator === null)
		{
			return $result;
		}

		$userId = $this->getUserId();
		if ($this->refuseRequest($requestId, $userId, $result))
		{
			return $result;
		}

		$status = $this->extract(
			$coordinator->getStatus($requestId, $userId, $this->readBinding($userId)),
			MessengerConfirmationCoordinator::DATA_STATUS,
			Status::class,
			$result,
		);

		if (!($status instanceof Status))
		{
			return $result;
		}

		$response = [
			'requestId' => $status->requestId,
			'status' => $status->status->value,
			'expiresAt' => $status->expiresAt,
			'pollAfter' => $status->pollAfter,
		];

		if ($status->code !== null)
		{
			$response['code'] = $status->code;
		}

		return $result->setData($response);
	}

	public function cancel(string $requestId): Result
	{
		$result = new Result();
		$coordinator = $this->getCoordinator($result);
		if ($coordinator === null)
		{
			return $result;
		}

		$userId = $this->getUserId();
		if ($this->refuseRequest($requestId, $userId, $result))
		{
			return $result;
		}

		$status = $this->extract(
			$coordinator->cancel($requestId, $userId, $this->readBinding($userId)),
			MessengerConfirmationCoordinator::DATA_STATUS,
			Status::class,
			$result,
		);

		if (!($status instanceof Status))
		{
			return $result;
		}

		return $result->setData([
			'requestId' => $status->requestId,
			'status' => $status->status->value,
		]);
	}

	private function getCoordinator(Result $result): ?MessengerConfirmationCoordinator
	{
		$serviceLocator = ServiceLocator::getInstance();
		$coordinator = $serviceLocator->has(self::COORDINATOR_SERVICE)
			? $serviceLocator->get(self::COORDINATOR_SERVICE)
			: null
		;

		if (!($coordinator instanceof MessengerConfirmationCoordinator))
		{
			$result->addError(MessengerConfirmationCoordinator::createUnavailableError());

			return null;
		}

		return $coordinator;
	}

	/**
	 * Keeps a call that cannot address any request away from the database: no login attempt behind it
	 * or an identifier that no request can ever have.
	 */
	private function refuseRequest(string $requestId, int $userId, Result $result): bool
	{
		if (preg_match(MessengerConfirmationState::REQUEST_ID_PATTERN, $requestId) !== 1)
		{
			$result->addError(MessengerConfirmationCoordinator::createNotFoundError());

			return true;
		}

		if ($userId <= 0)
		{
			$result->addError(MessengerConfirmationCoordinator::createForbiddenError());

			return true;
		}

		return false;
	}

	/**
	 * @param class-string $className
	 */
	private function extract(Result $source, string $key, string $className, Result $result): ?object
	{
		if (!$source->isSuccess())
		{
			$result->addErrors($source->getErrors());

			return null;
		}

		$value = $source->getData()[$key] ?? null;
		if (!($value instanceof $className))
		{
			$result->addError(MessengerConfirmationCoordinator::createUnavailableError());

			return null;
		}

		return $value;
	}

	private function getUserId(): int
	{
		return $this->loginAttempt->getUserId();
	}

	private function readBinding(int $userId): string
	{
		return $this->sessionBinding->read($userId, $this->loginAttempt->getId());
	}
}
