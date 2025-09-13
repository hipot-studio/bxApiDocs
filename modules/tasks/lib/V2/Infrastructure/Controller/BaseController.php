<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter\IsEnabledFilter;
use Bitrix\Tasks\V2\Infrastructure\Controller\Trait\AttributeAccessTrait;
use Bitrix\Tasks\V2\Infrastructure\Controller\Trait\AttributeAutowireTrait;
use Bitrix\Tasks\V2\Infrastructure\Controller\Trait\AttributePrefilterTrait;
use Bitrix\Tasks\V2\Internal\Entity\EntityInterface;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Context\AccessContextTrait;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\DiskFileRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\FlowRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\StageRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Public\Provider\CheckListProvider;
use Bitrix\Tasks\V2\Public\Provider\ReminderProvider;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;
use Bitrix\Tasks\V2\Public\Provider\TaskResultProvider;
use Bitrix\Tasks\V2\Public\Provider\TaskFromTemplateProvider;

abstract class BaseController extends JsonController
{
	use AttributeAccessTrait;
	use AccessContextTrait;
	use AttributeAutowireTrait;

	protected int $userId = 0;

	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				Entity\Task::class,
				'task',
				function (string $className, array $task): ?EntityInterface
				{
					return $this->getWithAccess($this, 'task', Entity\Task::mapFromArray($task));
				}
			),
			new ExactParameter(
				Entity\Template::class,
				'template',
				function (string $className, array $template): ?EntityInterface
				{
					return $this->getWithAccess($this, 'template', Entity\Template::mapFromArray($template));
				}
			),
			new ExactParameter(
				Entity\Group::class,
				'group',
				function (string $className, array $group)
				{
					return $this->getWithAccess($this, 'group', Entity\Group::mapFromArray($group));
				}
			),
			new ExactParameter(
				Entity\Flow::class,
				'flow',
				function (string $className, array $flow)
				{
					return $this->getWithAccess($this, 'flow', Entity\Flow::mapFromArray($flow));
				}
			),
			new ExactParameter(
				Entity\Result::class,
				'result',
				function (string $className, array $result)
				{
					return $this->getWithAccess($this, 'result', Entity\Result::mapFromArray($result));
				}
			),
			new ExactParameter(
				Entity\Task\Reminder::class,
				'reminder',
				function (string $className, array $reminder)
				{
					return $this->getWithAccess($this, 'reminder', Entity\Task\Reminder::mapFromArray($reminder));
				}
			),
			$this->getInjectionParameter(TaskRepositoryInterface::class),
			$this->getInjectionParameter(TemplateRepositoryInterface::class),
			$this->getInjectionParameter(StageRepositoryInterface::class),
			$this->getInjectionParameter(TaskLogRepositoryInterface::class),
			$this->getInjectionParameter(TaskProvider::class),
			$this->getInjectionParameter(GroupRepositoryInterface::class),
			$this->getInjectionParameter(FlowRepositoryInterface::class),
			$this->getInjectionParameter(UserRepositoryInterface::class),
			$this->getInjectionParameter(DiskFileRepositoryInterface::class),
			$this->getInjectionParameter(TaskResultProvider::class),
			$this->getInjectionParameter(LinkService::class),
			$this->getInjectionParameter(TaskRightService::class),
			$this->getInjectionParameter(CheckListProvider::class),
			$this->getInjectionParameter(TaskFromTemplateProvider::class),
			$this->getInjectionParameter(ReminderProvider::class),
		];
	}

	public function getDefaultPreFilters(): array
	{
		return array_merge(parent::getDefaultPreFilters(), [new IsEnabledFilter()]);
	}

	protected function init(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();

		$this->setAccessContext(
			context: (new Context($this->userId)),
		);

		parent::init();
	}
}
