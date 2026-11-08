<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Tasks\Integration\Search;
use Bitrix\Tasks\Internals;
use Bitrix\Tasks\Internals\Task\Search\RepositoryInterface;

class SearchIndexWriter
{
	public function index(array $taskData, RepositoryInterface $repository): void
	{
		Search\Task::index($taskData);
		Internals\SearchIndex::setTaskSearchIndex((int)$taskData['ID'], $repository);
	}
}
