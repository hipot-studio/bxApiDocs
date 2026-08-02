<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Control\Exception\TemplateDeleteException;
use Bitrix\Tasks\Control\Exception\TemplateUpdateException;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\OrmTemplateMapper;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TemplateMapper;
use Bitrix\Tasks\V2\Internal\Repository\Trait\ApplicationErrorTrait;
use Bitrix\Tasks\Validation\Validator\SerializedValidator;

class TemplateRepository implements TemplateRepositoryInterface
{
	use ApplicationErrorTrait;

	public function __construct(
		private readonly TemplateMapper $mapper,
		private readonly OrmTemplateMapper $ormTemplateMapper,
	)
	{
	}

	public function getById(int $id): Template|null
	{
		$template = TemplateTable::getByPrimary($id)->fetchObject();

		if ($template === null)
		{
			return null;
		}

		return $this->mapper->mapFromTemplateObject($template);
	}

	public function getByTaskId(int $taskId): Template|null
	{
		$template =
			TemplateTable::query()
				->setSelect([
					'ID',
					'TASK_ID',
					'TITLE',
					'DESCRIPTION',
					'CREATED_BY',
					'RESPONSIBLE_ID',
					'DEADLINE_AFTER',
					'START_DATE_PLAN_AFTER',
					'END_DATE_PLAN_AFTER',
					'REPLICATE',
					'CHECKLIST_DATA',
					'GROUP_ID',
					'PRIORITY',
				])
				->where('TASK_ID', $taskId)
				->where('ZOMBIE', 'N')
				->setOrder('ID')
				->setLimit(1)
				->exec()
				->fetchObject()
		;

		if ($template === null)
		{
			return null;
		}

		return $this->mapper->mapFromTemplateObject($template);
	}

	public function getTaskByTemplateId(int $templateId): ?Task
	{
		$row =
			TemplateTable::query()
				->setSelect(['TASK_ID'])
				->where('ID', $templateId)
				->where('ZOMBIE', 'N')
				->setLimit(1)
				->fetch()
		;

		$taskId = (int)($row['TASK_ID'] ?? 0);

		return $taskId > 0 ? new Task(id: $taskId) : null;
	}

	public function save(Template $entity): int
	{
		if ($entity->getId())
		{
			return $this->update($entity);
		}

		return $this->add($entity);
	}

	public function delete(int $id): void
	{
		$result = TemplateTable::delete($id);
		if (!$result->isSuccess())
		{
			throw new TemplateDeleteException($result->getError()?->getMessage());
		}
	}

	private function update(Template $entity): int
	{
		$currentTemplate = $this->getById($entity->getId());

		if ($currentTemplate === null)
		{
			throw new TemplateUpdateException('Not found');
		}

		$fields = $this->ormTemplateMapper->mapFromEntity($entity, true);
		unset($fields['ID']);

		if (empty($fields))
		{
			return $entity->getId();
		}

		$result = TemplateTable::update($entity->getId(), $fields);

		if (!$result->isSuccess())
		{
			throw new TemplateUpdateException();
		}

		return $result->getId();
	}

	private function add(Template $template): int
	{
		$fields = $this->ormTemplateMapper->mapFromEntity($template, true);

		$result = TemplateTable::add($fields);

		if (!$result->isSuccess())
		{
			$messages = $result->getErrorMessages();
			$message = 'Unknown template add error';
			if (!empty($messages))
			{
				$message = array_shift($messages);
			}

			throw new TemplateAddException($message);
		}

		return $result->getId();
	}

	public function invalidate(int $id): void
	{

	}

	public function getReplicateParams(int $templateId): ?array
	{
		$row = TemplateTable::query()
			->setSelect(['REPLICATE_PARAMS'])
			->where('ID', $templateId)
			->setLimit(1)
			->exec()
			->fetch();

		if (!$row)
		{
			return null;
		}

		$raw = (string)($row['REPLICATE_PARAMS'] ?? '');
		if ($raw === '')
		{
			return [];
		}

		$params = null;

		$validator = new SerializedValidator();
		if ($validator->validate($raw)->isSuccess())
		{
			$params = unserialize($raw, ['allowed_classes' => false]);
		}

		return $params;
	}

	public function getReplicableTemplateIds(int $afterId, int $limit): array
	{
		$rows = TemplateTable::query()
			->setSelect(['ID'])
			->where('REPLICATE', 'Y')
			->whereNot('ZOMBIE', 'Y')
			->where('ID', '>', $afterId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->exec()
			->fetchAll();

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}
}
