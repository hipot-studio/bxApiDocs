<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Receiver;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\VibeBalance;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventTarget;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\BalanceValidator;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\Storage\BalanceOptionStateStorage;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\TopUpRequestService;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\Vibecode\BalanceClient;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\BalancePullMessage;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventTargetProvider;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserListRetryPolicy;

class BalancePullReceiver extends AbstractReceiver
{
	private const LOCK_NAME = 'vibecodeconnector.balance_pull.portal';
	private const LOCK_RETRY_DELAY = 5;
	private const TERMINAL_ERROR_CODES = [
		'FEATURE_DISABLED',
		'NOT_REGISTERED',
		'PORTAL_SUSPENDED',
		'PROTOCOL_ERROR',
	];

	public function __construct(
		private readonly UserEventTargetProvider $targetProvider = new UserEventTargetProvider(),
		private readonly BalanceOptionStateStorage $storage = new BalanceOptionStateStorage(),
		private readonly UserListRetryPolicy $retryPolicy = new UserListRetryPolicy(),
		private readonly TopUpRequestService $topUpRequestService = new TopUpRequestService(),
	)
	{
	}

	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof BalancePullMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if (!Feature::isEnabled(VibeBalance::class))
		{
			return;
		}

		if (!$this->acquireLock(self::LOCK_NAME))
		{
			throw new RecoverableMessageException(
				message: 'Balance pull is already in progress',
				retryDelay: self::LOCK_RETRY_DELAY,
			);
		}

		try
		{
			$state = $this->storage->get();
			if ($state->version !== null && $state->requestedVersion <= $state->version)
			{
				return;
			}

			$target = $this->resolveTarget($message->pairingIss);
			if ($target === null || $target->endpointUrl !== $message->endpointUrl)
			{
				return;
			}

			$message->attemptCount++;
			$client = $this->createClient($target->endpointUrl);
			$result = $client->fetch();
			if (!$result->isSuccess())
			{
				$this->handleFailedResult($message, $client, $result->getErrors());
			}

			$data = $result->getData();
			$version = $data['version'] ?? null;
			$balance = $data['balance'] ?? null;
			if (!is_int($version) || $version < 0 || !is_string($balance) || !(new BalanceValidator())->isValid($balance))
			{
				throw new UnrecoverableMessageException('Invalid balance.get payload');
			}

			$previousBalance = $state->balance;
			$this->topUpRequestService->closeActiveOnBalanceGrowth($previousBalance, $balance);
			$this->storage->saveBalance($balance, $version, time());
		}
		finally
		{
			$this->releaseLock(self::LOCK_NAME);
		}
	}

	protected function createClient(string $endpointUrl): BalanceClient
	{
		return new BalanceClient($endpointUrl);
	}

	protected function resolveTarget(string $iss): ?UserEventTarget
	{
		return $this->targetProvider->resolve($iss);
	}

	protected function acquireLock(string $lockName): bool
	{
		return Application::getConnection()->lock($lockName, 0);
	}

	protected function releaseLock(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}

	private function handleFailedResult(
		BalancePullMessage $message,
		BalanceClient $client,
		array $errors,
	): never
	{
		$httpStatus = $client->getLastHttpStatus();
		if ($httpStatus === null || $httpStatus !== 200)
		{
			throw new RecoverableMessageException(
				message: 'Balance transport failed',
				retryDelay: $this->retryPolicy->forAttempt($message->attemptCount),
			);
		}

		$firstError = $errors[0] ?? null;
		$errorCode = $firstError === null ? 'PROTOCOL_ERROR' : (string)$firstError->getCode();
		if (in_array($errorCode, self::TERMINAL_ERROR_CODES, true))
		{
			throw new UnrecoverableMessageException("Balance request failed permanently: {$errorCode}");
		}

		if ($errorCode === 'RATE_LIMITED')
		{
			$customData = $firstError?->getCustomData();
			$retryAfter = is_array($customData) ? ($customData['retryAfterSeconds'] ?? null) : null;
			throw new RecoverableMessageException(
				message: 'Balance request was rate limited',
				retryDelay: $this->retryPolicy->forRateLimit($retryAfter),
			);
		}

		throw new RecoverableMessageException(
			message: 'Balance request failed',
			retryDelay: $this->retryPolicy->forAttempt($message->attemptCount),
		);
	}
}
