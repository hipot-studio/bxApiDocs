<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Template\Relation;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Read;
use Bitrix\Tasks\V2\Public\Command\Template\Relation\AddRelatedTaskTemplateCommand;
use Bitrix\Tasks\V2\Public\Command\Template\Relation\DeleteRelatedTaskTemplateCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\Relation\RelationTaskParams;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Template\Permission;
use Bitrix\Tasks\V2\Public\Provider\Relation\RelatedTaskTemplateProvider;
use Bitrix\Tasks\Validation\Rule\Count;

class Related extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Template.Relation.Related.list
	 */
	public function listAction(
		#[Permission\Read]
		Entity\Template $template,
		PageNavigation $pageNavigation,
		RelatedTaskTemplateProvider $relatedTaskTemplateProvider,
		SelectInterface|null $relationTaskSelect = null,
		bool $withIds = true,
	): array
	{
		$params = new RelationTaskParams(
			userId: $this->userId,
			taskId: 0,
			templateId: (int)$template->id,
			pager: Pager::buildFromPageNavigation($pageNavigation),
			checkRootAccess: false,
			select: $relationTaskSelect,
		);

		$response = [
			'tasks' => $relatedTaskTemplateProvider->getTasks($params),
		];

		if ($withIds)
		{
			$response['ids'] = $relatedTaskTemplateProvider->getTaskIds($params);
		}

		return $response;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Relation.Related.listByIds
	 */
	#[CloseSession]
	public function listByIdsAction(
		#[ElementsType(typeEnum: Type::Numeric)]
		array $taskIds,
		RelatedTaskTemplateProvider $relatedTaskTemplateProvider,
	): array
	{
		return [
			'tasks' => $relatedTaskTemplateProvider->getTasksByIds($taskIds, $this->userId),
		];
	}

	/**
	 * @ajaxAction tasks.V2.Template.Relation.Related.add
	 */
	public function addAction(
		#[Permission\Update]
		Entity\Template $template,
		#[Count(min: 1, max: 50)]
		#[Read]
		Entity\TaskCollection $tasks,
	): ?array
	{
		$response = [];

		foreach ($tasks as $relatedTask)
		{
			$relatedTaskId = (int)$relatedTask->id;

			$result = (new AddRelatedTaskTemplateCommand(
				templateId: (int)$template->id,
				relatedTaskId: $relatedTaskId,
			))->run();

			$response[$relatedTaskId] = $result->isSuccess();
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());
			}
		}

		return $response;
	}

	/**
	 * @ajaxAction tasks.V2.Template.Relation.Related.delete
	 */
	public function deleteAction(
		#[Permission\Update]
		Entity\Template $template,
		#[Count(min: 1, max: 50)]
		#[Read]
		Entity\TaskCollection $tasks,
	): ?array
	{
		$response = [];
		foreach ($tasks as $relatedTask)
		{
			$relatedTaskId = (int)$relatedTask->id;
			$result = (new DeleteRelatedTaskTemplateCommand(
				templateId: (int)$template->id,
				relatedTaskId: $relatedTaskId,
			))->run();

			$response[$relatedTaskId] = $result->isSuccess();
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());
			}
		}

		return $response;
	}
}
