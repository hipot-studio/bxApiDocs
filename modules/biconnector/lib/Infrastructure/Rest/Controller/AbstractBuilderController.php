<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller;

use Bitrix\BIConnector\Infrastructure\Rest\Access\BuilderFamilyGate;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\DescribesApi;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\RequiresSuperset;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\BuilderRefusal;
use Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder\BuilderRequestValidationException;
use Bitrix\BIConnector\Infrastructure\Rest\Support\BuilderRestAvailabilityCheck;
use Bitrix\BIConnector\Infrastructure\Rest\Support\BuilderRestArguments;
use Bitrix\BIConnector\Infrastructure\Rest\Support\BuilderResolvedHttpRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Infrastructure\Rest\Support\BuilderRestExceptionFactory;
use Bitrix\BIConnector\Infrastructure\Rest\Support\BuilderSupersetStartup;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderAvailabilityCheck;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaGuard;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderRestLimiter;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Exception\LogicException;
use Bitrix\Rest\V3\Exception\MethodNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * Entry point of the builder REST methods.
 *
 * While the builder family is disabled for the caller a method answers not found, the same code a method
 * outside the schema gets. Steps the REST server takes before the controller (scope check, usage
 * statistics, load limiter) still see the call. The schema is cached, so the flag is asked on every
 * call here rather than through #[Enabled].
 *
 * The quota is taken before the prefilters, so a call they refuse and a repeat the idempotency filter
 * answers from its store are counted too: the quota bounds the calls that reach the builder, whatever
 * they end with, and a stream of refused calls cannot pass it. Every call of a batch is counted on its own.
 * Between the quota of the caller and the quota of the portal the quota asks whether BI Builder is available
 * to the caller (BuilderAvailabilityCheck): a caller without access to BI is refused with the code of that
 * reason after spending a quota of their own, and leaves the quota and the slots of the portal to the others.
 * An action marked #[DescribesApi] describes the API, not the data of the portal: availability is not asked
 * for it, the quota is.
 *
 * Only a call the quota and the prefilters let through starts the BI instance or checks its status, and only
 * for an action marked #[RequiresSuperset], as an MCP tool does before it runs: a refused call does not wake
 * the instance. A hit asks for it once (BuilderSupersetStartup), so a batch does not repeat the request.
 */
abstract class AbstractBuilderController extends RestController
{
	private ?BuilderQuotaGuard $quotaGuard = null;
	private ?Result $quota = null;
	private bool $requiresSuperset = false;
	private ?Action $argumentAction = null;
	private ?Request $argumentRequest = null;
	private array $decodedArguments = [];

	public function getAutoWiredParameters(): array
	{
		$parameters = parent::getAutoWiredParameters();
		$uri = $this->getMethodDescription()?->actionUri ?? '';
		if (BuilderArgumentContract::forMethod($uri) === [])
		{
			return $parameters;
		}
		foreach ($parameters as $parameter)
		{
			if ($parameter->getClassName() !== Request::class)
			{
				continue;
			}
			$constructor = $parameter->getConstructor();
			$parameter->setConstructor(function (string $className) use ($constructor): Request {
				$this->clearArgumentCapture();
				$source = $this->getRequest();
				$batch = $this->getServer()?->getMethod() === 'batch';
				$decoded = $batch ? $source->getJsonList()->getValues() : [];
				try
				{
					if ($batch)
					{
						$this->request = BuilderResolvedHttpRequest::fromDecoded($source, $decoded);
					}
					$bound = $constructor($className);
					$this->argumentAction = $this->getCurrentAction();
					$this->argumentRequest = $bound;
					$this->decodedArguments = $batch ? $decoded : $source->getJsonList()->getValues();

					return $bound;
				}
				catch (\Throwable $throwable)
				{
					$this->clearArgumentCapture();

					throw $throwable;
				}
				finally
				{
					$this->request = $source;
				}
			});
		}

		return $parameters;
	}

	protected function processBeforeAction(Action $action): bool
	{
		$this->clearArgumentCapture();
		$userId = (int)$this->getCurrentUser()?->getId();
		if (!(new BuilderFamilyGate($this->createFlagGate()))->isEnabled($userId))
		{
			throw new MethodNotFoundException($this->getMethodDescription()?->actionUri ?? $action->getName());
		}

		$method = new \ReflectionMethod($this, $this->generateActionMethodName($action->getName()));
		$quota = $this->getQuotaGuard()->acquire(
			$userId,
			$this->getCallerKey(),
			$this->getQuotaClass($method),
			$this->getAvailabilityCheck($method),
		);
		if (!$quota->isSuccess())
		{
			throw BuilderRestExceptionFactory::from($quota);
		}
		// set before anything else may throw: runProcessingThrowable() gives the slots back then
		$this->quota = $quota;
		$this->requiresSuperset = $method->getAttributes(RequiresSuperset::class) !== [];

		return parent::processBeforeAction($action) === true;
	}

	/**
	 * Reached once the prefilters let the call through: they run after processBeforeAction().
	 */
	protected function getActionResponse(Action $action)
	{
		try
		{
			$uri = $this->getMethodDescription()?->actionUri ?? '';
			if (BuilderArgumentContract::forMethod($uri) !== [])
			{
				$arguments = $action->getArguments();
				$bound = null;
				foreach ($arguments as $argument)
				{
					if ($argument instanceof Request)
					{
						$bound = $argument;

						break;
					}
				}
				if ($bound === null || $this->argumentAction !== $action || $this->argumentRequest !== $bound)
				{
					throw new SystemException('Builder request binding capture is missing.');
				}
				$result = BuilderRestArguments::prepare($uri, $bound, $this->decodedArguments);
				if (!$result->isSuccess())
				{
					throw BuilderRestExceptionFactory::from($result);
				}
				BuilderRestArguments::apply($bound, $result->getData());
			}
			if ($this->requiresSuperset)
			{
				BuilderSupersetStartup::once(fn() => $this->initializeSuperset());
			}

			return parent::getActionResponse($action);
		}
		finally
		{
			$this->clearArgumentCapture();
		}
	}

	private function clearArgumentCapture(): void
	{
		$this->argumentAction = null;
		$this->argumentRequest = null;
		$this->decodedArguments = [];
	}

	protected function processAfterAction(Action $action, $result)
	{
		$this->releaseQuota();

		return parent::processAfterAction($action, $result);
	}

	protected function runProcessingThrowable(\Throwable $throwable): void
	{
		$this->clearArgumentCapture();
		$this->releaseQuota();

		parent::runProcessingThrowable($throwable);
	}

	/**
	 * A framework refusal of the request arguments answers with the builder code INVALID_ARGUMENT and keeps
	 * its validation list; a refusal the builder raised itself is left as it is.
	 */
	protected function buildErrorFromException(\Exception $e)
	{
		if ($e instanceof RequestValidationException && !$e instanceof BuilderRefusal)
		{
			return parent::buildErrorFromException(BuilderRequestValidationException::fromRequestValidation($e));
		}

		return parent::buildErrorFromException($e);
	}

	/**
	 * Data of a successful operation result; a refused one leaves as the REST exception of its code.
	 *
	 * @param int|null $entityId The record the request addresses; a NotFound refusal names it.
	 */
	protected function requireResultData(Result $result, ?int $entityId = null): array
	{
		if (!$result->isSuccess())
		{
			throw BuilderRestExceptionFactory::from($result, $entityId);
		}

		return $result->getData();
	}

	protected function createFlagGate(): FlagGate
	{
		return new FlagGate();
	}

	protected function createQuotaGuard(): BuilderQuotaGuard
	{
		return new BuilderRestLimiter();
	}

	protected function createAvailabilityCheck(): BuilderAvailabilityCheck
	{
		return new BuilderRestAvailabilityCheck();
	}

	protected function initializeSuperset(): void
	{
		SupersetInitializer::initializeOrCheckSupersetStatus();
	}

	private function getQuotaGuard(): BuilderQuotaGuard
	{
		return $this->quotaGuard ??= $this->createQuotaGuard();
	}

	private function getAvailabilityCheck(\ReflectionMethod $method): BuilderAvailabilityCheck
	{
		if ($method->getAttributes(DescribesApi::class) === [])
		{
			return $this->createAvailabilityCheck();
		}

		return new class() implements BuilderAvailabilityCheck {
			public function check(int $userId): Result
			{
				return new Result();
			}
		};
	}

	private function getQuotaClass(\ReflectionMethod $method): BuilderQuotaClass
	{
		$attributes = $method->getAttributes(Quota::class);
		if ($attributes === [])
		{
			throw new LogicException(
				new SystemException('Builder REST action ' . static::class . '::' . $method->getName() . ' declares no quota.'),
			);
		}

		return $attributes[0]->newInstance()->class;
	}

	/**
	 * The application or webhook the call comes from; empty when the call has no REST server behind it.
	 */
	private function getCallerKey(): string
	{
		$server = $this->getServer();
		if ($server === null)
		{
			return '';
		}

		$identity = (string)($server->getClientId() ?: $server->getPasswordId() ?: '');
		if ($identity === '')
		{
			return '';
		}

		return hash('sha256', $server->getAuthType() . ':' . $identity);
	}

	private function releaseQuota(): void
	{
		if ($this->quota === null)
		{
			return;
		}

		$quota = $this->quota;
		$this->quota = null;
		$this->getQuotaGuard()->release($quota);
	}
}
