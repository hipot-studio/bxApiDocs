<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\DefaultLanguage;
use Bitrix\Rest\V3\DeferredModeAwareInterface;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Dto\Mapping\MapperRegistry;
use Bitrix\Rest\V3\Enum\CallChannel;
use Bitrix\Rest\V3\Exception\ClassRequireAttributeException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\LogicException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use Bitrix\Rest\V3\Exception\TooManyAttributesException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Interaction\Response\Response;
use Bitrix\Rest\V3\Interaction\Response\ResponseWithRelations;
use Bitrix\Rest\V3\Controller\ActionFilter\IdempotencyFilter;
use Bitrix\Rest\V3\Idempotency\IdempotencyKeyResolver;
use Bitrix\Rest\V3\Idempotency\IdempotencyService;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Schema\Scope;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use CRestServer;
use ReflectionParameter;
use Throwable;

/**
 * Base class for all REST V3 controllers.
 *
 * @note Idempotency wiring: this class attaches {@see IdempotencyFilter} to both
 *       the pre- and post-filter chains as the SAME instance. Any subclass that
 *       overrides {@see getDefaultPreFilters()} or {@see getDefaultPostFilters()}
 *       MUST call `parent::` and preserve the filter instance, otherwise actions
 *       declared under that subclass will silently lose idempotency support.
 */
abstract class RestController extends Controller
{
	protected ?string $responseLanguage = null;

	protected Scope $processedScope;

	protected ?string $dtoClass = null;

	protected ?array $queryParams = null;

	protected ?MethodDescription $methodDescription = null;

	protected ?CRestServer $server = null;

	private CallChannel $callChannel = CallChannel::RestTransport;

	/**
	 * Cached idempotency filter instance, shared between pre- and post-filter
	 * chains. The shared instance is required so that private state set in
	 * `IdempotencyFilter::onBeforeAction` survives to `IdempotencyFilter::onAfterAction`.
	 */
	private ?IdempotencyFilter $idempotencyFilter = null;

	protected function getDefaultPreFilters()
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = $this->getIdempotencyFilter();

		return $filters;
	}

	protected function getDefaultPostFilters()
	{
		$filters = parent::getDefaultPostFilters();
		$filters[] = $this->getIdempotencyFilter();

		return $filters;
	}

	private function getIdempotencyFilter(): IdempotencyFilter
	{
		if ($this->idempotencyFilter === null)
		{
			$locator = \Bitrix\Main\DI\ServiceLocator::getInstance();
			$this->idempotencyFilter = new IdempotencyFilter(
				$locator->get(IdempotencyService::class),
				$locator->get(IdempotencyKeyResolver::class),
			);
		}

		return $this->idempotencyFilter;
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new Parameter(
				Request::class,
				function (string $className)
				{
					/** @var Request $className */
					$controllerReflection = new \ReflectionClass($this);
					$attributes = $controllerReflection->getAttributes(DtoType::class);

					// check if there is any dto in controller
					if (!empty($attributes))
					{
						if (count($attributes) > 1)
						{
							throw new TooManyAttributesException($className, DtoType::class, 1);
						}
						/** @var DtoType $dtoTypeAttribute */
						$dtoTypeAttribute = $attributes[0]->newInstance();
						$dtoType = $dtoTypeAttribute->type;
					}
					else if ($this->dtoClass !== null)
					{
						$dtoType = $this->dtoClass;
					}
					else
					{
						throw new ClassRequireAttributeException(get_class($this), DtoType::class);
					}

					// create request
					return $className::create($this->getRequest(), $dtoType, ['scope' => $this->processedScope]);
				},
			),
		];
	}

	public function setResponseLanguage(string $responseLanguage): void
	{
		$this->responseLanguage = $responseLanguage;
	}

	protected function getActionResponse(Action $action)
	{
		$response = $action->runWithSourceParametersList();

		if ($response instanceof ResponseWithRelations)
		{
			$args = $action->getArguments();
			$request = null;
			if (isset($args['request']) && $args['request'] instanceof Request)
			{
				$request = $args['request'];
			}
			else
			{
				foreach ($args as $arg)
				{
					if ($arg instanceof Request)
					{
						$request = $arg;
						break;
					}
				}
			}

			if ($request !== null)
			{
				$response->setParentRequest($request);
				$this->updateRequestRelationFilters($request, $response);

				if (!empty($request->getRelations()))
				{
					$response->setRelations($request->getRelations());
				}
			}
		}

		return $response;
	}

	private function updateRequestRelationFilters(Request $request, Response $response): void
	{
		if (!$request->select)
		{
			return;
		}
		$relationFields = $request->select->getRelationFields();
		$relationFilterValues = $this->getResultRelationFilterValues($relationFields, $response);

		foreach ($request->getRelations() as $relation)
		{
			if (isset($relationFilterValues[$relation->getFromField()]))
			{
				$relationFilter = FilterStructure::create([$relation->getToField(), $relationFilterValues[$relation->getFromField()]], $relation->getRequest()->getDtoClass(), $relation->getRequest());
				$relation->getRequest()->filter = $relationFilter;
			}
		}
	}

	private function getResultRelationFilterValues(array $relationFields, Response $response): array
	{
		$result = [];
		if (isset($response->items))
		{
			foreach ($response->items as $item)
			{
				$this->fillResultRelationFilterField($item->toArray(), $relationFields, $result);
			}
		}
		else
		{
			$this->fillResultRelationFilterField($response->item->toArray(), $relationFields, $result);
		}

		return $result;
	}

	private function fillResultRelationFilterField(array $item, array $relationFields, array &$result): void
	{
		foreach ($relationFields as $relationField)
		{
			if (isset($item[$relationField]))
			{
				$result[$relationField][] = $item[$relationField];
			}
		}
	}

	/**
	 * @param Throwable $throwable
	 */
	protected function runProcessingThrowable(Throwable $throwable): void
	{
		if ($throwable instanceof BinderArgumentException)
		{
			$throwable = $this->convertBinderArgumentException($throwable);
		}
		elseif (!is_subclass_of($throwable, RestException::class))
		{
			$throwable = new InternalException($throwable);
		}

		parent::runProcessingThrowable($throwable);
	}

	/**
	 * Maps Engine binder failures for scalar/action parameters to V3 request validation errors.
	 */
	private function convertBinderArgumentException(BinderArgumentException $exception): RestException
	{
		$errors = $exception->getErrors();
		if ($errors)
		{
			return new RequestValidationException($errors);
		}

		$fieldName = $this->resolveBinderParameterName($exception);
		if ($fieldName === null)
		{
			return new InternalException($exception);
		}

		$message = $exception->getMessage();

		if (
			str_starts_with($message, 'Could not find value for parameter {')
			&& !str_contains($message, ' to build auto wired argument ')
		)
		{
			return new RequiredFieldInRequestException($fieldName);
		}

		if (
			preg_match(
				'/^Invalid value \{.*\} to match with parameter \{.+\}\. Should be value of type (.+)\.$/s',
				$message,
				$matches,
			)
		)
		{
			return new InvalidRequestFieldTypeException($fieldName, $matches[1]);
		}

		if (str_starts_with($message, 'Invalid value to match parameter: '))
		{
			return new RequestValidationException([new Error($message, $fieldName)]);
		}

		return new InternalException($exception);

	}

	private function resolveBinderParameterName(BinderArgumentException $exception): ?string
	{
		$parameter = $exception->getParameter();
		if ($parameter instanceof ReflectionParameter)
		{
			return $parameter->getName();
		}

		if (is_string($parameter) && $parameter !== '')
		{
			return $parameter;
		}

		return null;
	}

	protected function writeToLogException(\Throwable $e): void
	{
		if ($e instanceof SkipWriteToLogException)
		{
			return;
		}

		if ($e instanceof InternalException && $e->getPrevious())
		{
			// get exception with real internal message
			$e = $e->getPrevious();
		}

		parent::writeToLogException($e);
	}

	protected function buildErrorFromException(\Exception $e)
	{
		if ($e instanceof RestException)
		{
			$output = $e->output($this->getResponseLanguage());

			return new Error($e->getMessage(), $e->getStatus(), !empty($output) ? $output : null);
		}

		return parent::buildErrorFromException($e);
	}

	public function setProcessedScope(Scope $scope): self
	{
		$this->processedScope = $scope;

		return $this;
	}

	public function getResponseLanguage(): string
	{
		return $this->responseLanguage ??= DefaultLanguage::get();
	}

	public function getDtoClass(): ?string
	{
		return $this->dtoClass;
	}

	public function setDtoClass(?string $dtoClass): RestController
	{
		$this->dtoClass = $dtoClass;

		return $this;
	}

	public function getQueryParams(): ?array
	{
		return $this->queryParams;
	}

	public function setQueryParams(?array $queryParams): RestController
	{
		$this->queryParams = $queryParams;

		return $this;
	}

	/**
	 * Schema description of the running method. Set by every call channel while building
	 * the controller; filters that need the schema of the route read it from here.
	 */
	public function getMethodDescription(): ?MethodDescription
	{
		return $this->methodDescription;
	}

	public function setMethodDescription(?MethodDescription $methodDescription): self
	{
		$this->methodDescription = $methodDescription;

		return $this;
	}

	public function getServer(): ?CRestServer
	{
		return $this->server;
	}

	public function setCallChannel(CallChannel $channel): self
	{
		$this->callChannel = $channel;

		return $this;
	}

	public function getCallChannel(): CallChannel
	{
		return $this->callChannel;
	}

	protected function isOrmDeferredMode(): bool
	{
		$server = $this->getServer();

		return $server instanceof DeferredModeAwareInterface && $server->isDeferredMode();
	}

	public function setServer(?CRestServer $server = null): self
	{
		$this->server = $server;

		return $this;
	}

	/**
	 * Resolves the DTO class from #[DtoType] attribute or $this->dtoClass property.
	 */
	protected function resolveDtoClass(): ?string
	{
		if ($this->dtoClass !== null)
		{
			return $this->dtoClass;
		}

		$attributes = (new \ReflectionClass($this))->getAttributes(DtoType::class);
		if (!empty($attributes))
		{
			/** @var DtoType $dtoTypeAttribute */
			$dtoTypeAttribute = $attributes[0]->newInstance();

			return $dtoTypeAttribute->type;
		}

		return null;
	}

	/**
	 * Returns the Mapper instance declared via #[MappedBy] on the controller's DTO class.
	 *
	 * @throws LogicException When DTO class cannot be resolved or has no MappedBy attribute.
	 */
	protected function getDtoMapper(): Mapper
	{
		$dtoClass = $this->resolveDtoClass();
		if ($dtoClass === null)
		{
			throw new LogicException(
				new SystemException('Cannot resolve DTO class: no #[DtoType] attribute or $dtoClass property set on ' . static::class),
			);
		}

		$mapper = MapperRegistry::getForDto($dtoClass);
		if ($mapper === null)
		{
			throw new LogicException(
				new SystemException("DTO class {$dtoClass} has no #[MappedBy] attribute or #[OrmEntity] is present — MappedBy is disabled."),
			);
		}

		return $mapper;
	}
}
