<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Request;

use Bitrix\Landing\Copilot\Generation\Error;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Step\Base\AIStep;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntities;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Landing\Copilot\Model\RequestToEntitiesTable;

abstract class RequestMultiple extends AIStep
{
	private const MAX_SEND_ATTEMPTS = 2;

	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * @var RequestEntityDto[]
	 */
	protected array $entities = [];

	/**
	 * @var array<Request>
	 */
	protected array $requests = [];

	/**
	 * @var array<string, true>
	 */
	private array $handledSendFailureEntityKeys = [];

	abstract public static function getConnectorClass(): string;

	/**
	 * @inheritdoc
	 */
	protected function initialize(): void
	{
		$this->requests = Request::getByGeneration($this->generation->getId(), $this->stepId);
	}

	/**
	 * @inheritdoc
	 */
	public function execute(): bool
	{
		if (!parent::execute())
		{
			return false;
		}

		if (!isset($this->siteData))
		{
			return false;
		}

		$this->cleanupExpiredRequests();

		if (
			$this->isStarted()
			&& $this->applyResponses()
		)
		{
			$this->changed = true;
		}

		if (!$this->isFinished())
		{
			$this->sendRequests();
			if ($this->applyResponses())
			{
				$this->changed = true;
			}
		}

		return true;
	}

	private function cleanupExpiredRequests(): void
	{
		foreach ($this->requests as $requestId => $request)
		{
			if (!$request->isTimeIsOver())
			{
				continue;
			}

			if ($this->handleExpiredRequest($request))
			{
				unset($this->requests[$requestId]);
			}
		}
	}

	/**
	 * Handles an expired request.
	 *
	 * Return true when the request should be removed from in-memory request list.
	 */
	protected function handleExpiredRequest(Request $request): bool
	{
		$request->setDeleted();

		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function isAsync(): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function isStarted(): bool
	{
		foreach ($this->getEntitiesToRequest() as $entity)
		{
			if (isset($entity->requestId, $this->requests[$entity->requestId]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @inheritdoc
	 */
	public function isFinished(): bool
	{
		$entities = $this->getEntitiesToRequest();
		if (empty($entities))
		{
			return true;
		}

		if (empty($this->requests))
		{
			return false;
		}

		foreach ($entities as $entity)
		{
			if ($this->isEntitySendFailureHandled($entity))
			{
				continue;
			}

			if (!isset($entity->requestId, $this->requests[$entity->requestId]))
			{
				return false;
			}

			if (!$this->requests[$entity->requestId]->isApplied())
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function clearErrors(): void
	{
		foreach ($this->requests as $request)
		{
			if (
				$request
				&& $request->getError()
			)
			{
				$request->setDeleted();
				unset($this->requests[$request->getId()]);
			}
		}
	}

	/**
	 * Collect landing entities references to requests
	 * Should be overwritten in child classes
	 * @return array<RequestEntityDto>
	 */
	abstract protected function getEntitiesToRequest(): array;

	/**
	 * Send responses for all data
	 * Should be overwritten in child classes.
	 * @return bool - success flag
	 */
	abstract protected function sendRequests(): bool;

	/**
	 * Set requests result to site data
	 * Should be overwritten in child classes.
	 * @return bool - if change at least one entity
	 */
	abstract protected function applyResponses(): bool;

	protected function assertTimerNotExceeded(): void
	{
		if (!$this->generation->getTimer()->check())
		{
			throw new \RuntimeException("The maximum execution time has been reached, step {$this->stepId} was aborted");
		}
	}

	/**
	 * @param callable(): ?Request $sender
	 */
	protected function sendEntityRequestWithRetry(RequestEntityDto $entity, callable $sender): ?Request
	{
		$lastException = null;

		for ($attempt = 1; $attempt <= self::MAX_SEND_ATTEMPTS; $attempt++)
		{
			try
			{
				$request = $sender();
				if ($request instanceof Request)
				{
					return $request;
				}

				$lastException = new GenerationException(
					GenerationErrors::notSendRequest,
					'AI request was not saved',
				);
			}
			catch (GenerationException $exception)
			{
				if (!$this->isRetryableSendException($exception))
				{
					throw $exception;
				}

				$lastException = $exception;
			}
		}

		if (!$lastException)
		{
			$lastException = new GenerationException(GenerationErrors::notSendRequest);
		}

		return $this->handleFinalEntitySendFailure($entity, $lastException);
	}

	protected function handleEntitySendFailure(RequestEntityDto $entity, string $reason): bool
	{
		return false;
	}

	protected function attachAppliedEntityErrorRequest(
		RequestEntityDto $entity,
		RequestEntities $entityType,
		string $reason,
	): bool
	{
		if (!$this->isRequestEntityLinkable($entity))
		{
			return false;
		}

		$request = new Request($this->generation->getId(), $this->stepId);
		$error = Error::createError(Errors::requestFail);
		$reason = trim($reason);
		if ($reason !== '')
		{
			$error->message = trim($error->message . ': ' . $reason, ': ');
		}

		if (!$request->saveError($error))
		{
			return false;
		}

		$requestId = $request->getId();
		if ($requestId === null)
		{
			return false;
		}

		$res = RequestToEntitiesTable::add([
			'REQUEST_ID' => $requestId,
			'ENTITY_TYPE' => $entityType->value,
			'LANDING_ID' => $entity->landingId,
			'BLOCK_ID' => $entity->blockId,
			'NODE_CODE' => $entity->nodeCode,
			'POSITION' => $entity->position,
		]);
		if (!$res->isSuccess())
		{
			$request->setDeleted();

			return false;
		}

		if (!$request->setApplied())
		{
			$request->setDeleted();

			return false;
		}

		$this->requests[$requestId] = $request;
		$entity->requestId = $requestId;

		return true;
	}

	private function handleFinalEntitySendFailure(
		RequestEntityDto $entity,
		GenerationException $exception,
	): ?Request
	{
		if (!$this->handleEntitySendFailure($entity, $exception->getMessage()))
		{
			throw $exception;
		}

		$this->markEntitySendFailureHandled($entity);
		$this->changed = true;

		if ($this->isSendFailureLimitReached())
		{
			throw new GenerationException(
				GenerationErrors::errorInRequest,
				$this->buildSendFailureLimitMessage(),
			);
		}

		return null;
	}

	private function isRetryableSendException(GenerationException $exception): bool
	{
		return in_array($exception->getErrorCode(), [
			GenerationErrors::notSendRequest,
			GenerationErrors::errorInRequest,
		], true);
	}

	private function markEntitySendFailureHandled(RequestEntityDto $entity): void
	{
		$this->handledSendFailureEntityKeys[$this->buildEntitySendFailureKey($entity)] = true;
	}

	private function isEntitySendFailureHandled(RequestEntityDto $entity): bool
	{
		return isset($this->handledSendFailureEntityKeys[$this->buildEntitySendFailureKey($entity)]);
	}

	private function isSendFailureLimitReached(): bool
	{
		$total = count($this->getEntitiesToRequest());
		if ($total <= 0)
		{
			return false;
		}

		return count($this->handledSendFailureEntityKeys) >= (int)ceil($total / 2);
	}

	private function buildSendFailureLimitMessage(): string
	{
		return sprintf(
			'Too many AI request send failures in step %d (%d/%d)',
			(int)$this->stepId,
			count($this->handledSendFailureEntityKeys),
			count($this->getEntitiesToRequest()),
		);
	}

	private function buildEntitySendFailureKey(RequestEntityDto $entity): string
	{
		return implode(':', [
			(int)($entity->landingId ?? 0),
			(int)($entity->blockId ?? 0),
			(string)($entity->nodeCode ?? ''),
			(int)($entity->position ?? 0),
			sha1((string)($entity->prompt ?? '')),
		]);
	}

	private function isRequestEntityLinkable(RequestEntityDto $entity): bool
	{
		return isset(
			$entity->landingId,
			$entity->blockId,
			$entity->nodeCode,
			$entity->position,
		);
	}
}
