<?php

namespace Bitrix\TasksMobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\TasksMobile\Provider\ChecklistProvider;

class Checklist extends Controller
{
	public function configureActions(): array
	{
		return [
			'getSettings' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
			'get' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * @ajaxAction tasksmobile.Checklist.get
	 */
	public function getAction(int $entityId = 0, string $entityType = 'task', int $taskId = 0): ?array
	{
		// backward compatibility with the taskId parameter
		if ($entityId === 0 && $taskId > 0)
		{
			$entityId = $taskId;
		}

		if ($entityId <= 0)
		{
			$this->addError(new Error('entityId is required'));

			return null;
		}

		$allowedTypes = ['task', 'template'];
		if (!in_array($entityType, $allowedTypes, true))
		{
			$this->addError(new Error("Invalid entityType '{$entityType}': allowed values are 'task', 'template'"));

			return null;
		}

		$userId = (int)$this->getCurrentUser()->getId();

		if ($entityType === 'template')
		{
			if (!TemplateAccessController::can($userId, ActionDictionary::ACTION_TEMPLATE_READ, $entityId))
			{
				$this->addError(new Error('Access denied'));

				return null;
			}

			$type = Type::Template;
		}
		else
		{
			if (!TaskAccessController::can($userId, ActionDictionary::ACTION_TASK_READ, $entityId))
			{
				$this->addError(new Error('Access denied'));

				return null;
			}

			$type = Type::Task;
		}

		return (new ChecklistProvider($userId))->getChecklistV2($entityId, $type);
	}
}
