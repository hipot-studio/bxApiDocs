<?php

namespace Bitrix\BIConnector\Superset\Operation;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Integrator\Sender;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Config\ConfigContainer;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

final class DestructiveOperationResolver
{
	private const CODE_GENERATION_MISMATCH = 'GENERATION_MISMATCH';

	private const REFUSAL_CODES = [
		'INTENT_REQUIRED',
		'INTENT_NOT_CONFIRMED',
		self::CODE_GENERATION_MISMATCH,
		'PROTOCOL_REQUIRED',
		'SERVER_NAME_REQUIRED',
		'SERVER_AMBIGUOUS',
		'SERVER_NOT_FOUND',
		'ACCOUNT_NOT_RESOLVED',
		'OPERATION_MISMATCH',
		'OPERATION_NOT_ACCEPTED',
	];

	private const STATE_FIELD = 'state';
	private const STATE_DONE = 'DONE';
	private const STATE_FAILED = 'FAILED';
	private const SERVER_GENERATION_FIELD = 'serverGeneration';

	private const GENERATION_AWAIT_TTL = 2592000;

	public static function resolve(string $operation, IntegratorResponse $response): DestructiveOperationOutcome
	{
		$operationId = self::readOperationId($response);
		$outcome = self::classify($operationId, $response);

		if (in_array(self::CODE_GENERATION_MISMATCH, self::readErrorCodes($response), true))
		{
			SupersetInitializer::requestServerGenerationRefresh();
			$outcome = self::awaitUnknownGeneration($outcome);
		}

		if ($outcome !== DestructiveOperationOutcome::Undetermined)
		{
			(new OperationIntentService())->drop($operationId);
		}

		if ($outcome !== DestructiveOperationOutcome::Success)
		{
			self::journal($operation, $operationId, $response, $outcome);
		}

		return $outcome;
	}

	public static function resolveOperationState(string $operation, string $operationId): DestructiveOperationOutcome
	{
		$state = IntegratorFactory::getInstance()->getOperationStatus($operationId);
		$outcome = self::classifyOperationState($state);

		if ($outcome !== DestructiveOperationOutcome::Undetermined)
		{
			(new OperationIntentService())->drop($operationId);
		}

		if ($outcome !== DestructiveOperationOutcome::Success)
		{
			self::journal($operation, $operationId, $state, $outcome);
		}

		return $outcome;
	}

	/**
	 * For callers that repeat the operation: an outcome awaiting the generation is remembered, so the next
	 * attempt waits for the refresh series instead of being sent. One-off callers leave it alone: for them
	 * the awaiting outcome is final, and a mark of theirs would refuse a later attempt of a repeating caller.
	 */
	public static function awaitGenerationBeforeRetry(DestructiveOperationOutcome $outcome): DestructiveOperationOutcome
	{
		if ($outcome === DestructiveOperationOutcome::AwaitingGeneration)
		{
			self::getStorage()->set(SupersetInitializer::GENERATION_AWAIT_KEY, 1, self::GENERATION_AWAIT_TTL);
		}

		return $outcome;
	}

	/**
	 * The attempt that awaited the generation is cancelled: its mark must not refuse a later attempt.
	 */
	public static function dropGenerationAwait(): void
	{
		self::getStorage()->delete(SupersetInitializer::GENERATION_AWAIT_KEY);
	}

	/**
	 * For callers that repeat the operation: whether the request may be sent now.
	 *
	 * @return DestructiveOperationOutcome|null AwaitingGeneration while the generation refresh is running,
	 *     FinalRefusal when it ended without a generation, null when nothing is awaited.
	 */
	public static function checkGenerationAwait(): ?DestructiveOperationOutcome
	{
		$storage = self::getStorage();
		if (!$storage->get(SupersetInitializer::GENERATION_AWAIT_KEY, 0))
		{
			return null;
		}

		if (ConfigContainer::getConfigContainer()->getServerGeneration() !== '')
		{
			$storage->delete(SupersetInitializer::GENERATION_AWAIT_KEY);

			return null;
		}

		if ($storage->get(SupersetInitializer::GENERATION_REFRESH_KEY, 0))
		{
			return DestructiveOperationOutcome::AwaitingGeneration;
		}

		$storage->delete(SupersetInitializer::GENERATION_AWAIT_KEY);
		SupersetInitializerLogger::logErrors(
			[new Error('The generation of the superset server did not arrive')],
			[
				'message' => 'Destructive superset operation is not repeated',
				'outcome' => DestructiveOperationOutcome::FinalRefusal->value,
			],
		);

		return DestructiveOperationOutcome::FinalRefusal;
	}

	/**
	 * An empty generation means "unknown", not "another incarnation": the operation waits for the generation
	 * of the own server instead of being refused for good.
	 */
	private static function awaitUnknownGeneration(DestructiveOperationOutcome $outcome): DestructiveOperationOutcome
	{
		if (
			$outcome !== DestructiveOperationOutcome::FinalRefusal
			|| ConfigContainer::getConfigContainer()->getServerGeneration() !== ''
		)
		{
			return $outcome;
		}

		return DestructiveOperationOutcome::AwaitingGeneration;
	}

	private static function classify(string $operationId, IntegratorResponse $response): DestructiveOperationOutcome
	{
		if (!$response->hasErrors())
		{
			self::adoptServerGeneration($response->getData());

			return DestructiveOperationOutcome::Success;
		}

		if (self::isRefusedWithoutExecution($response))
		{
			return DestructiveOperationOutcome::FinalRefusal;
		}

		if ($operationId === '')
		{
			return DestructiveOperationOutcome::Undetermined;
		}

		return self::readServiceOperationState($operationId);
	}

	private static function isRefusedWithoutExecution(IntegratorResponse $response): bool
	{
		if ($response->getStatus() === IntegratorResponse::STATUS_INNER_ERROR)
		{
			return true;
		}

		foreach (self::readErrorCodes($response) as $code)
		{
			if (in_array($code, self::REFUSAL_CODES, true))
			{
				return true;
			}
		}

		return false;
	}

	private static function readServiceOperationState(string $operationId): DestructiveOperationOutcome
	{
		return self::classifyOperationState(IntegratorFactory::getInstance()->getOperationStatus($operationId));
	}

	private static function classifyOperationState(IntegratorResponse $state): DestructiveOperationOutcome
	{
		if ($state->hasErrors())
		{
			return DestructiveOperationOutcome::Undetermined;
		}

		$data = $state->getData();
		self::adoptServerGeneration($data);
		$stateName = is_array($data) ? (string)($data[self::STATE_FIELD] ?? '') : '';

		return match ($stateName)
		{
			self::STATE_DONE => DestructiveOperationOutcome::Success,
			self::STATE_FAILED => DestructiveOperationOutcome::FinalRefusal,
			default => DestructiveOperationOutcome::Undetermined,
		};
	}

	private static function adoptServerGeneration(mixed $data): void
	{
		$generation = is_array($data) ? ($data[self::SERVER_GENERATION_FIELD] ?? null) : null;
		if (is_string($generation))
		{
			ConfigContainer::getConfigContainer()->adoptServerGeneration($generation);
		}
	}

	private static function getStorage(): PersistentStorageInterface
	{
		return ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	private static function journal(
		string $operation,
		string $operationId,
		IntegratorResponse $response,
		DestructiveOperationOutcome $outcome,
	): void
	{
		$context = [
			'message' => 'Destructive superset operation is not applied to the local state',
			'operation' => $operation,
			'operationId' => $operationId,
			'outcome' => $outcome->value,
			'status' => $response->getStatus(),
			'codes' => self::readErrorCodes($response),
		];

		if ($outcome === DestructiveOperationOutcome::FinalRefusal)
		{
			SupersetInitializerLogger::logErrors($response->getErrors(), $context);

			return;
		}

		SupersetInitializerLogger::logWarning($response->getErrors(), $context);
	}

	public static function readOperationId(IntegratorResponse $response): string
	{
		$data = $response->getData();
		$operationId = is_array($data) ? ($data[Sender::FIELD_OPERATION_ID] ?? null) : null;

		return is_scalar($operationId) ? (string)$operationId : '';
	}

	/**
	 * @return string[]
	 */
	private static function readErrorCodes(IntegratorResponse $response): array
	{
		return array_map(
			static fn(Error $error): string => (string)$error->getCode(),
			$response->getErrors(),
		);
	}
}
