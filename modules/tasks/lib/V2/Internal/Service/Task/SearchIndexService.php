<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\Internals\Task\Search\Exception\SearchIndexException;
use Bitrix\Tasks\Internals\Task\Search\Exception\TaskNotFoundException;
use Bitrix\Tasks\Internals\Task\Search\Repository\TaskRepository;
use Bitrix\Tasks\Internals\Task\Search\RepositoryInterface;
use Closure;

final class SearchIndexService
{
	private readonly Closure $repositoryFactory;

	public function __construct(
		private readonly SearchIndexWriter $searchIndexWriter,
		?Closure $repositoryFactory = null,
	)
	{
		$this->repositoryFactory = $repositoryFactory
			?? static fn(int $taskId): RepositoryInterface => new TaskRepository($taskId)
		;
	}

	/** @throws SearchIndexException */
	public function index(int $taskId): void
	{
		if ($taskId <= 0)
		{
			return;
		}

		try
		{
			$repository = ($this->repositoryFactory)($taskId);
			$taskData = $repository->getTask();
		}
		catch (TaskNotFoundException)
		{
			return;
		}

		$taskData['SE_TAG'] = $taskData['TAGS'] ?? [];

		$this->searchIndexWriter->index($taskData, $repository);
	}
}
