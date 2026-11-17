<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\V2\Internal\Entity\Template\TemplateReplicateParams;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\ReplicateParamsMapper;

class TemplateReplicateParamsRepository implements TemplateReplicateParamsRepositoryInterface
{
	public function __construct(
		private readonly ReplicateParamsMapper $replicateParamsMapper,
	)
	{
	}

	public function getByTaskId(int $taskId, bool $includeDeleted = false): ?TemplateReplicateParams
	{
		if ($includeDeleted)
		{
			$template =
				TemplateTable::query()
					->setSelect(['ID', 'TASK_ID', 'REPLICATE', 'REPLICATE_PARAMS', 'REPLICATE_TYPE', 'ZOMBIE'])
					->where('TASK_ID', $taskId)
					->addOrder('ZOMBIE', 'ASC')
					->addOrder('ID', 'DESC')
					->setLimit(1)
					->exec()
					->fetch()
			;

			return is_array($template) ? $this->mapRowToTemplateReplicateParams($template) : null;
		}

		return $this->getByTaskIds([$taskId])[$taskId] ?? null;
	}

	public function getByTaskIds(array $taskIds): array
	{
		Collection::normalizeArrayValuesByInt($taskIds, false);

		if ($taskIds === [])
		{
			return [];
		}

		$templates =
			TemplateTable::query()
				->setSelect(['ID', 'TASK_ID', 'REPLICATE', 'REPLICATE_PARAMS', 'REPLICATE_TYPE', 'ZOMBIE'])
				->whereIn('TASK_ID', $taskIds)
				->where('ZOMBIE', 'N')
				->exec()
				->fetchAll()
		;

		$result = [];

		foreach ($templates as $template)
		{
			$taskId = (int)($template['TASK_ID'] ?? 0);
			$result[$taskId] = $this->mapRowToTemplateReplicateParams($template);
		}

		return $result;
	}

	public function getByTemplateId(int $templateId, bool $includeDeleted = false): ?TemplateReplicateParams
	{
		if ($includeDeleted)
		{
			$template =
				TemplateTable::query()
					->setSelect(['ID', 'REPLICATE', 'REPLICATE_PARAMS', 'REPLICATE_TYPE', 'ZOMBIE'])
					->where('ID', $templateId)
					->setLimit(1)
					->exec()
					->fetch()
			;

			return is_array($template) ? $this->mapRowToTemplateReplicateParams($template) : null;
		}

		return $this->getByTemplateIds([$templateId])[$templateId] ?? null;
	}

	public function getByTemplateIds(array $templateIds): array
	{
		Collection::normalizeArrayValuesByInt($templateIds, false);

		if ($templateIds === [])
		{
			return [];
		}

		$templates =
			TemplateTable::query()
				->setSelect(['ID', 'REPLICATE', 'REPLICATE_PARAMS', 'REPLICATE_TYPE', 'ZOMBIE'])
				->whereIn('ID', $templateIds)
				->where('ZOMBIE', 'N')
				->exec()
				->fetchAll()
		;

		$result = [];

		foreach ($templates as $template)
		{
			$templateId = (int)($template['ID'] ?? 0);
			$result[$templateId] = $this->mapRowToTemplateReplicateParams($template);
		}

		return $result;
	}

	public function invalidateByTemplateId(int $templateId): void
	{
	}

	public function invalidateByTaskId(int $taskId): void
	{
	}

	private function mapRowToTemplateReplicateParams(array $template): TemplateReplicateParams
	{
		$templateDeleted = ($template['ZOMBIE'] ?? '') === 'Y';

		return new TemplateReplicateParams(
			templateId: (int)($template['ID'] ?? 0),
			replicateParams: $this->replicateParamsMapper->mapToValueObject(
				$template['REPLICATE_PARAMS'] ?? null,
			),
			replicate: !$templateDeleted && ($template['REPLICATE'] ?? '') === 'Y',
			regularityType: RegularityType::fromValue($template['REPLICATE_TYPE'] ?? null),
			templateDeleted: $templateDeleted,
		);
	}
}
