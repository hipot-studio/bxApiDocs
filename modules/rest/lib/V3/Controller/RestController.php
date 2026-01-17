<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\ClassRequireAttributeException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use Bitrix\Rest\V3\Exception\TooManyAttributesException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Interaction\Response\Response;
use Bitrix\Rest\V3\Interaction\Response\ResponseWithRelations;
use Bitrix\Rest\V3\Schema\Scope;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Throwable;

abstract class RestController extends Controller
{
	protected string $responseLanguage;

	protected Scope $processedScope;

	protected ?string $dtoClass = null;

	protected ?array $queryParams = null;

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
			$request = $args['request'];
			$response->setParentRequest($request);
			$this->updateRequestRelationFilters($request, $response);

			if (!empty($request->getRelations()))
			{
				$response->setRelations($request->getRelations());
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
				$this->fillResultRelationFilterField($item, $relationFields, $result);
			}
		}
		else
		{
			$this->fillResultRelationFilterField($response->item, $relationFields, $result);
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
		if (!is_subclass_of($throwable, RestException::class))
		{
			$throwable = new InternalException($throwable);
		}

		parent::runProcessingThrowable($throwable);
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
			$output = $e->output($this->responseLanguage);

			return new Error($e->getMessage(), $e->getStatus(), !empty($output) ? $output : null);
		}

		return parent::buildErrorFromException($e);
	}

	public function setProcessedScope(Scope $scope): self
	{
		$this->processedScope = $scope;

		return $this;
	}

	public function getResponseLanguage(): ?string
	{
		return $this->responseLanguage;
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
}
