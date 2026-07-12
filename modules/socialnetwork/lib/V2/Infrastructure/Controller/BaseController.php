<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Validation\ValidationException;
use Bitrix\Socialnetwork\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\CollectionAttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityCollectionInterface;
use ReflectionAttribute;
use ReflectionMethod;
use Throwable;

abstract class BaseController extends JsonController
{
	protected ?Context $context = null;
	protected int $userId = 0;

	public function getAccessContext(): Context
	{
		return $this->context ?? new Context(0);
	}

	public function setAccessContext(Context $context): static
	{
		$this->context = $context;

		return $this;
	}

	protected function init(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();

		$this->setAccessContext(
			context: (new Context($this->userId)),
		);

		parent::init();
	}

	/**
	 * @template T of EntityInterface|EntityCollectionInterface
	 * @param T $entity
	 * @return T|null
	 */
	protected function getWithAccess(
		Controller $controller,
		string $parameterName,
		EntityInterface|EntityCollectionInterface $entity,
		array $parameters = [],
	): null|EntityInterface|EntityCollectionInterface
	{
		$request = $controller->getRequest();
		$parameters = array_merge(
			$request->toArray(),
			$request->getJsonList()->toArray(),
			$parameters
		);

		$accessAttributes = $this->getArgumentAccessAttributes($controller, $parameterName);
		foreach ($accessAttributes as $accessAttribute)
		{
			$hasAccess = match (true)
			{
				$entity instanceof EntityCollectionInterface
					&& $accessAttribute instanceof CollectionAttributeAccessInterface
						=> $accessAttribute->checkCollection($entity, $this->getAccessContext(), $parameters),
				$entity instanceof EntityInterface
					&& $accessAttribute instanceof AttributeAccessInterface
						=> $accessAttribute->check($entity, $this->getAccessContext(), $parameters),
				default => false,
			};

			if (!$hasAccess)
			{
				if ($accessAttribute instanceof AccessUserErrorInterface)
				{
					return $this->buildForbiddenResponse($accessAttribute->getUserError());
				}

				return $this->buildForbiddenResponse();
			}
		}

		return $entity;
	}

	protected function getArgumentAccessAttributes(Controller $controller, string $parameterName): array
	{
		$action = $this->getControllerAction($controller);
		$reflector = new ReflectionMethod($controller, $action);

		$args = $reflector->getParameters();

		$accessAttributes = [];
		foreach ($args as $arg)
		{
			if ($arg->getName() !== $parameterName)
			{
				continue;
			}

			$attributes = array_merge(
				$arg->getAttributes(AttributeAccessInterface::class, ReflectionAttribute::IS_INSTANCEOF),
				$arg->getAttributes(CollectionAttributeAccessInterface::class, ReflectionAttribute::IS_INSTANCEOF),
			);

			return array_map(
				static fn (ReflectionAttribute $attribute): object => $attribute->newInstance(),
				$attributes
			);
		}

		return $accessAttributes;
	}

	protected function buildForbiddenResponse(?Error $error = null): mixed
	{
		$error ??= $this->buildForbiddenError();

		$this->errorCollection->setError($error);

		return null;
	}

	protected function buildForbiddenError(string $code = 'access_denied'): Error
	{
		$message = Loc::getMessage('SOCIALNETWORK_ACCESS_ERROR_DEFAULT') ?? $code;

		return new Error($message, $code);
	}

	protected function getControllerAction(Controller $controller): string
	{
		$reflector = new \ReflectionClass($controller);
		$methodReflector = $reflector->getMethod('getCurrentAction');
		$methodReflector->setAccessible(true);

		$method = $methodReflector->invoke($controller)->getName();

		return $method . 'Action';
	}

	// Skip logging for user-input validation errors. Parent's shouldWriteToLogException() is private, so filter here.
	protected function writeToLogException(Throwable $e): void
	{
		if ($e instanceof ValidationException)
		{
			return;
		}

		parent::writeToLogException($e);
	}
}
