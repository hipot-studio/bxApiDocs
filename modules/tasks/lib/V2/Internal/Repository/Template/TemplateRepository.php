<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Control\Exception\TemplateDeleteException;
use Bitrix\Tasks\Control\Exception\TemplateUpdateException;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\OrmTemplateMapper;
use Bitrix\Tasks\V2\Internal\Repository\Trait\ApplicationErrorTrait;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\TemplateMapper;

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
}
