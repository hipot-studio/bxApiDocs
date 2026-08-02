<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\V2\Internal\Entity\Template\TemplateReplicateParams;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\ReplicateParamsMapper;

class TemplateReplicateParamsRepository implements TemplateReplicateParamsRepositoryInterface
{
	public function __construct(
		private readonly ReplicateParamsMapper $replicateParamsMapper,
	)
	{
	}

	public function getByTaskId(int $taskId): ?TemplateReplicateParams
	{
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
				->setSelect(['ID', 'TASK_ID', 'REPLICATE', 'REPLICATE_PARAMS'])
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

	public function getByTemplateId(int $templateId): ?TemplateReplicateParams
	{
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
				->setSelect(['ID', 'REPLICATE', 'REPLICATE_PARAMS'])
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
		return new TemplateReplicateParams(
			templateId: (int)($template['ID'] ?? 0),
			replicateParams: $this->replicateParamsMapper->mapToValueObject(
				$template['REPLICATE_PARAMS'] ?? null,
			),
			replicate: ($template['REPLICATE'] ?? '') === 'Y',
		);
	}
}
