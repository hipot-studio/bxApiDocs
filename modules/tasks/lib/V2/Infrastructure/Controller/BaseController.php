<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\AutoWire\Parameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Tasks\DI\Container;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\IsEnabledFilter;
use Bitrix\Tasks\V2\Internal\Entity\EntityInterface;
use Bitrix\Tasks\V2\Internal\Entity\EntityCollectionInterface;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Message;
use Bitrix\Tasks\V2\Internal\Repository\TaskLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Copy\Config\CopyConfig;
use Bitrix\Tasks\V2\Public\Provider\Params\Relation\RelationTaskSelect;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Relation\RelationTemplateSelect;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\TemplateParams;
use ReflectionAttribute;
use ReflectionMethod;

abstract class BaseController extends JsonController
{
	protected ?Context $context = null;

	protected int $userId = 0;

	public function getAutoWiredParameters(): array
	{
		return array_merge(
			$this->getExactParameters(),
			$this->getInjectionParameters(),
		);
	}

	public function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[new IsEnabledFilter()]
		);
	}

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

	protected function getInjectionParameter(string $className): Parameter
	{
		return new Parameter(
			$className,
			static fn (): object => Container::getInstance()->get($className),
		);
	}

	protected function getExactParameters(): array
	{
		return [
			new ExactParameter(
				Entity\Task::class,
				'task',
				fn (string $className, array $task): ?EntityInterface
				=> $this->getWithAccess($this, 'task', Entity\Task::mapFromArray($task)),
			),
			new ExactParameter(
				Entity\Task::class,
				'task',
				fn (string $className, int $taskId): ?EntityInterface
				=> $this->getWithAccess($this, 'task', new Entity\Task(id: $taskId)),
			),
			new ExactParameter(
				Entity\Task::class,
				'targetTask',
				fn (string $className, int $targetTaskId): ?EntityInterface
				=> $this->getWithAccess($this, 'targetTask', new Entity\Task(id: $targetTaskId)),
			),
			new ExactParameter(
				Entity\Task::class,
				'relatedTask',
				fn (string $className, array $relatedTask): ?EntityInterface
				=> $this->getWithAccess($this, 'relatedTask', Entity\Task::mapFromArray($relatedTask)),
			),
			new ExactParameter(
				Entity\Task::class,
				'relatedTask',
				fn (string $className, int $relatedTaskId): ?EntityInterface
				=> $this->getWithAccess($this, 'relatedTask',new Entity\Task(id: $relatedTaskId)),
			),
			new ExactParameter(
				Message::class,
				'message',
				fn (string $className, array $message): ?EntityInterface
				=> $this->getWithAccess($this, 'message', Message::mapFromArray($message)),
			),
			new ExactParameter(
				Message::class,
				'message',
				fn (string $className, int $messageId): ?EntityInterface
				=> $this->getWithAccess($this, 'message', new Message(id: $messageId)),
			),
			new ExactParameter(
				Entity\TaskCollection::class,
				'tasks',
				fn (string $className, array $tasks): ?Entity\EntityCollectionInterface
				=> $this->getWithAccess($this, 'tasks', Entity\TaskCollection::mapFromArray($tasks)),
			),
			new ExactParameter(
				Entity\TaskCollection::class,
				'tasks',
				fn (string $className, array $taskIds): ?Entity\EntityCollectionInterface
				=> $this->getWithAccess($this, 'tasks', Entity\TaskCollection::mapFromIds($taskIds)),
			),
			new ExactParameter(
				Entity\Template::class,
				'template',
				fn (string $className, int $templateId): ?EntityInterface
				=> $this->getWithAccess($this, 'template', new Entity\Template(id: $templateId)),
			),
			new ExactParameter(
				Entity\Template::class,
				'template',
				fn (string $className, array $template): ?EntityInterface
				=> $this->getWithAccess($this, 'template', Entity\Template::mapFromArray($template)),
			),
			new ExactParameter(
				Entity\Template\TemplateCollection::class,
				'templates',
				fn (string $className, array $templateIds): ?Entity\EntityCollectionInterface
				=> $this->getWithAccess($this, 'templates', Entity\Template\TemplateCollection::mapFromIds($templateIds)),
			),
			new ExactParameter(
				Entity\Group::class,
				'group',
				fn (string $className, array $group): ?EntityInterface
				=> $this->getWithAccess($this, 'group', Entity\Group::mapFromArray($group)),
			),
			new ExactParameter(
				Entity\Group::class,
				'group',
				fn (string $className, int $groupId): ?EntityInterface
				=> $this->getWithAccess($this, 'group', new Entity\Group(id: $groupId)),
			),
			new ExactParameter(
				Entity\Flow::class,
				'flow',
				fn (string $className, array $flow): ?EntityInterface
				=> $this->getWithAccess($this, 'flow', Entity\Flow::mapFromArray($flow)),
			),
			new ExactParameter(
				Entity\Flow::class,
				'flow',
				fn (string $className, int $flowId): ?EntityInterface
				=> $this->getWithAccess($this, 'flow', new Entity\Flow(id: $flowId)),
			),
			new ExactParameter(
				Entity\Result::class,
				'result',
				fn (string $className, array $result): ?EntityInterface
				=> $this->getWithAccess($this, 'result', Entity\Result::mapFromArray($result)),
			),
			new ExactParameter(
				Entity\Result::class,
				'result',
				fn (string $className, int $resultId): ?EntityInterface
				=> $this->getWithAccess($this, 'result', new Entity\Result(id: $resultId)),
			),
			new ExactParameter(
				Entity\ResultCollection::class,
				'results',
				fn (string $className, array $results): ?EntityCollectionInterface
				=> $this->getWithAccess($this, 'results', Entity\ResultCollection::mapFromArray($results)),
			),
			new ExactParameter(
				Entity\ResultCollection::class,
				'results',
				fn (string $className, array $resultIds): ?EntityCollectionInterface
				=> $this->getWithAccess($this, 'results', Entity\ResultCollection::mapFromIds($resultIds)),
			),
			new ExactParameter(
				Entity\UserCollection::class,
				'users',
				fn (string $className, array $userIds): ?EntityCollectionInterface
				=> $this->getWithAccess($this, 'users', Entity\UserCollection::mapFromIds($userIds)),
			),
			new ExactParameter(
				Entity\Task\Reminder::class,
				'reminder',
				fn (string $className, array $reminder): ?EntityInterface
				=> $this->getWithAccess($this, 'reminder', Entity\Task\Reminder::mapFromArray($reminder)),
			),
			new ExactParameter(
				Entity\Task\ElapsedTime::class,
				'elapsedTime',
				fn (string $className, array $elapsedTime): ?EntityInterface
				=> $this->getWithAccess($this, 'elapsedTime', Entity\Task\ElapsedTime::mapFromArray($elapsedTime)),
			),
			new ExactParameter(
				Entity\Task\ElapsedTime::class,
				'elapsedTimeId',
				fn (string $className, int $elapsedTimeId): ?EntityInterface
				=> $this->getWithAccess($this, 'elapsedTime', new Entity\Task\ElapsedTime(id: $elapsedTimeId)),
			),
			new ExactParameter(
				SelectInterface::class,
				'relationTaskSelect',
				fn (string $className, array $relationTaskSelect): ?SelectInterface
				=> new RelationTaskSelect($relationTaskSelect),
			),
			new ExactParameter(
				SelectInterface::class,
				'relationTemplateSelect',
				fn (string $className, array $relationTemplateSelect): ?SelectInterface
				=> new RelationTemplateSelect($relationTemplateSelect),
			),
			new ExactParameter(
				TaskParams::class,
				'taskSelect',
				fn (string $className, ?array $taskSelect = null): ?TaskParams
				=> TaskParams::mapFromArray($taskSelect),
			),
			new ExactParameter(
				TemplateParams::class,
				'templateSelect',
				fn (string $className, ?array $templateSelect = null): ?TemplateParams
				=> TemplateParams::mapFromArray($templateSelect),
			),
			new ExactParameter(
				Entity\Task\GanttLink::class,
				'ganttLink',
				fn (string $className, array $ganttLink): ?EntityInterface
				=> $this->getWithAccess($this, 'ganttLink', Entity\Task\GanttLink::mapFromArray($ganttLink)),
			),
			new ExactParameter(
				Entity\Task\State::class,
				'state',
				fn (string $className, array $state): ?EntityInterface
				=> Entity\Task\State::mapFromArray($state),
			),
			new ExactParameter(
				Entity\UserFieldCollection::class,
				'userFields',
				fn (string $className, array $userFields): ?EntityCollectionInterface
				=> $this->getWithAccess($this, 'userFields', Entity\UserFieldCollection::mapFromArray($userFields))
			)
		];
	}

	protected function getInjectionParameters(): array
	{
		return [
			$this->getInjectionParameter(TemplateRepositoryInterface::class),
			$this->getInjectionParameter(TaskLogRepositoryInterface::class),
		];
	}

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
			/** @var AttributeAccessInterface $accessAttribute */
			if (!$accessAttribute->check($entity, $this->getAccessContext(), $parameters))
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

			return array_map(
				static fn (ReflectionAttribute $attribute): AttributeAccessInterface => $attribute->newInstance(),
				$arg->getAttributes(AttributeAccessInterface::class, ReflectionAttribute::IS_INSTANCEOF)
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

	protected function buildForbiddenError($code = 'Access denied'): Error
	{
		$message = Loc::getMessage('TASKS_ACCESS_ERROR_DEFAULT') ?? 'Access denied';

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
}
