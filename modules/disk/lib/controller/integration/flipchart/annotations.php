<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\Integration\Flipchart;

use Bitrix\Disk\Controller\Integration\Filter\FlipchartAnnotationsFilter;
use Bitrix\Disk\Document\Flipchart\Annotation\AccessContext;
use Bitrix\Disk\Document\Flipchart\Annotation\AnnotationService;
use Bitrix\Disk\Document\Flipchart\Annotation\ContextResolver;
use Bitrix\Disk\Document\Flipchart\Annotation\RequestValidator;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\Json;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Response;
use Bitrix\Main\Result;

final class Annotations extends Controller
{
	private const ALLOWED_METHODS = [
		'list' => 'GET',
		'count' => 'GET',
		'get' => 'GET, PUT, PATCH',
		'put' => 'GET, PUT, PATCH',
		'patch' => 'GET, PUT, PATCH',
		'delete' => 'POST',
		'restore' => 'POST',
	];

	private ?AccessContext $accessContext = null;

	/** @var array<string, mixed>|null */
	private ?array $input = null;

	private bool $hasUnexpectedFailure = false;

	public function configureActions(): array
	{
		$contextResolver = new ContextResolver();
		$requestValidator = new RequestValidator();

		return [
			'list' => $this->buildActionConfiguration(
				RequestValidator::OPERATION_LIST,
				[HttpMethod::METHOD_GET],
				$contextResolver,
				$requestValidator,
			),
			'count' => $this->buildActionConfiguration(
				RequestValidator::OPERATION_COUNT,
				[HttpMethod::METHOD_GET],
				$contextResolver,
				$requestValidator,
			),
			'get' => $this->buildActionConfiguration(
				RequestValidator::OPERATION_GET,
				[HttpMethod::METHOD_GET],
				$contextResolver,
				$requestValidator,
			),
			'put' => $this->buildActionConfiguration(
				RequestValidator::OPERATION_CREATE,
				[HttpMethod::METHOD_PUT],
				$contextResolver,
				$requestValidator,
			),
			'patch' => $this->buildActionConfiguration(
				RequestValidator::OPERATION_PATCH,
				[HttpMethod::METHOD_PATCH],
				$contextResolver,
				$requestValidator,
			),
			'delete' => $this->buildActionConfiguration(
				RequestValidator::OPERATION_DELETE,
				[HttpMethod::METHOD_POST],
				$contextResolver,
				$requestValidator,
			),
			'restore' => $this->buildActionConfiguration(
				RequestValidator::OPERATION_RESTORE,
				[HttpMethod::METHOD_POST],
				$contextResolver,
				$requestValidator,
			),
		];
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new Parameter(
				AnnotationService::class,
				static fn(string $className): AnnotationService => new AnnotationService(),
			),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function acceptResolvedRequest(AccessContext $context, array $input): void
	{
		$this->accessContext = $context;
		$this->input = $input;
	}

	public function listAction(AnnotationService $service): Result
	{
		return $this->execute($service, 'list');
	}

	public function countAction(AnnotationService $service): Result
	{
		return $this->execute($service, 'count');
	}

	public function getAction(AnnotationService $service): Result
	{
		return $this->execute($service, 'get');
	}

	public function putAction(AnnotationService $service): Result
	{
		return $this->execute($service, 'put');
	}

	public function patchAction(AnnotationService $service): Result
	{
		return $this->execute($service, 'patch');
	}

	public function deleteAction(AnnotationService $service): Result
	{
		return $this->execute($service, 'delete');
	}

	public function restoreAction(AnnotationService $service): Result
	{
		return $this->execute($service, 'restore');
	}

	protected function processAfterAction(Action $action, $result): HttpResponse
	{
		if ($result instanceof HttpResponse)
		{
			return $result;
		}

		if ($result instanceof Result)
		{
			return $this->createResultResponse($result, $action);
		}

		$errors = $this->getErrors();

		return $this->createErrorResponse($errors[0] ?? self::internalError(), $action);
	}

	protected function runProcessingThrowable(\Throwable $throwable)
	{
		$this->hasUnexpectedFailure = true;
		$this->addError(self::internalError());
	}

	public function finalizeResponse(Response $response): void
	{
		if (!$this->hasUnexpectedFailure || !$response instanceof HttpResponse)
		{
			return;
		}

		$errorResponse = $this->createErrorResponse(self::internalError());
		$response->setContent($errorResponse->getContent());
		$response->setStatus($errorResponse->getStatus());
	}

	/**
	 * @param list<string> $methods
	 * @return array<string, array<int, object|string>>
	 */
	private function buildActionConfiguration(
		string $operation,
		array $methods,
		ContextResolver $contextResolver,
		RequestValidator $requestValidator,
	): array
	{
		return [
			'-prefilters' => [
				Authentication::class,
				Csrf::class,
				HttpMethod::class,
			],
			'+prefilters' => [
				new FlipchartAnnotationsFilter($operation, $contextResolver, $requestValidator),
				new HttpMethod($methods),
			],
		];
	}

	private function execute(AnnotationService $service, string $operation): Result
	{
		if ($this->accessContext === null || $this->input === null)
		{
			$result = new Result();
			$result->addError(self::internalError());

			return $result;
		}

		return match ($operation)
		{
			'list' => $service->list($this->accessContext, $this->input),
			'count' => $service->count($this->accessContext, $this->input),
			'get' => $service->get($this->accessContext, $this->input),
			'put' => $service->put($this->accessContext, $this->input),
			'patch' => $service->patch($this->accessContext, $this->input),
			'delete' => $service->delete($this->accessContext, $this->input),
			'restore' => $service->restore($this->accessContext, $this->input),
		};
	}

	private function createResultResponse(Result $result, ?Action $action = null): Json
	{
		if (!$result->isSuccess())
		{
			return $this->createErrorResponse($result->getError() ?? self::internalError(), $action);
		}

		$data = $result->getData();
		$status = $data['status'] ?? null;
		$body = $data['response'] ?? null;
		if (!is_int($status) || !is_array($body))
		{
			return $this->createErrorResponse(self::internalError(), $action);
		}

		$response = new Json($body);
		$response->setStatus($status);

		return $response;
	}

	private function createErrorResponse(Error $error, ?Action $action = null): Json
	{
		$customData = $error->getCustomData();
		$status = is_array($customData) ? ($customData['status'] ?? null) : null;
		$code = $error->getCode();
		$message = $error->getMessage();

		if ($code === HttpMethod::ERROR_INVALID_HTTP_METHOD)
		{
			$status = 405;
			$code = 'method_not_allowed';
			$message = 'Method is not allowed.';
		}
		elseif (!is_int($status) || !is_string($code) || $code === '')
		{
			$status = 500;
			$code = 'internal_error';
			$message = 'Internal server error.';
		}

		$body = [
			'code' => $code,
			'message' => $message,
		];
		if (is_array($customData) && is_array($customData['details'] ?? null))
		{
			$body['details'] = $customData['details'];
		}

		$response = new Json($body);
		$response->setStatus($status);

		$actionName = $action?->getName();
		if ($status === 405 && isset(self::ALLOWED_METHODS[$actionName]))
		{
			$response->addHeader('Allow', self::ALLOWED_METHODS[$actionName]);
		}

		return $response;
	}

	private static function internalError(): Error
	{
		return new Error('Internal server error.', 'internal_error', ['status' => 500]);
	}
}
