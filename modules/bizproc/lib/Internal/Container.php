<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal;

use Bitrix\Bizproc\Internal\Repository\StorageTypeRepository\StorageTypeRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageItemRepository\StorageItemRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\StorageFieldRepository\StorageFieldRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageItemMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageTypeMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\StorageFieldMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\WorkflowStateMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\TaskArchiveMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\TaskArchiveTasksMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\TaskMapper;
use Bitrix\Bizproc\Internal\Repository\Mapper\TaskUserMapper;
use Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository\TaskArchiveRepository;
use Bitrix\Bizproc\Internal\Repository\TaskArchiveRepository\TaskArchiveTasksRepository;
use Bitrix\Bizproc\Internal\Repository\TaskRepository\TaskRepository;
use Bitrix\Bizproc\Internal\Repository\WorkflowStateRepository\WorkflowStateRepository;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\WorkflowTemplateRepository;
use Bitrix\Bizproc\Public\Service\Task\ArchiveTaskService;
use Bitrix\Bizproc\Public\Command\WorkflowState\ClearStuckWorkflowCommand\ClearStuckWorkflowCommandHandler;
use Bitrix\Main\DI\ServiceLocator;

class Container
{
	protected array $types = [];

	public static function getInstance(): ?self
	{
		return self::getService('bizproc.internal.container');
	}

	private static function getService(string $name): mixed
	{
		$prefix = 'bizproc.';
		if (mb_strpos($name, $prefix) !== 0)
		{
			$name = $prefix . $name;
		}
		$locator = ServiceLocator::getInstance();

		return $locator->has($name)
			? $locator->get($name)
			: null
		;
	}

	public static function getWorkflowStateRepository(): ?WorkflowStateRepository
	{
		return self::getService('bizproc.workflow.state.repository');
	}

	public static function getWorkflowStatRepositoryMapper(): ?WorkflowStateMapper
	{
		return self::getService('bizproc.workflow.state.repository.mapper');
	}

	public static function getStorageTypeRepository(): ?StorageTypeRepositoryInterface
	{
		return self::getService('bizproc.storage.type.repository');
	}

	public static function getStorageItemRepository(): ?StorageItemRepositoryInterface
	{
		return self::getService('bizproc.storage.item.repository');
	}

	public static function getStorageFieldRepository(): ?StorageFieldRepositoryInterface
	{
		return self::getService('bizproc.storage.field.repository');
	}

	public static function getWorkflowTemplateRepository(): ?WorkflowTemplateRepository
	{
		return self::getService('bizproc.workflow.template.repository');
	}

	public static function getStorageTypeRepositoryMapper(): ?StorageTypeMapper
	{
		return self::getService('bizproc.storage.type.repository.mapper');
	}

	public static function getStorageItemRepositoryMapper(): ?StorageItemMapper
	{
		return self::getService('bizproc.storage.item.repository.mapper');
	}

	public static function getStorageFieldRepositoryMapper(): ?StorageFieldMapper
	{
		return self::getService('bizproc.storage.field.repository.mapper');
	}

	public static function getTaskRepository(): ?TaskRepository
	{
		return self::getService('bizproc.task.repository');
	}

	public static function getTaskRepositoryMapper(): ?TaskMapper
	{
		return self::getService('bizproc.task.repository.mapper');
	}

	public static function getTaskUserRepositoryMapper(): ?TaskUserMapper
	{
		return self::getService('bizproc.task.user.repository.mapper');
	}

	public static function getTaskArchiveRepositoryMapper(): ?TaskArchiveMapper
	{
		return self::getService('bizproc.task.archive.repository.mapper');
	}

	public static function getTaskArchiveRepository(): ?TaskArchiveRepository
	{
		return self::getService('bizproc.task.archive.repository');
	}

	public static function getTaskArchiveTasksRepositoryMapper(): ?TaskArchiveTasksMapper
	{
		return self::getService('bizproc.task.archive.tasks.repository.mapper');
	}

	public static function getTaskArchiveTasksRepository(): ?TaskArchiveTasksRepository
	{
		return self::getService('bizproc.task.archive.tasks.repository');
	}

	public static function getArchiveTaskService(): ?ArchiveTaskService
	{
		return self::getService('bizproc.archive.task.service');
	}

	public static function getClearStuckWorkflowCommandHandler(): ClearStuckWorkflowCommandHandler
	{
		return self::getService('bizproc.clear.stuck.workflow.command.handler');
	}
}
