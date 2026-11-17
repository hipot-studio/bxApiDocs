<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Attachment\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Detach implements AttributeAccessInterface
{
	use AccessControllerTrait;

	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$ids = $parameters['ids'] ?? [];

		if (!is_array($ids) || empty($ids))
		{
			return false;
		}

		$accessController = $this->getAccessController(Type::Task, $context);
		$adapter = $this->getAdapter($entity);

		/** @var TaskModel $model */
		$model = $adapter->create();

		$diskFileRepository = Container::getInstance()->getDiskFileRepository();
		$attachmentsCreatedByMap = $diskFileRepository->getOwnerIdsByFileIds(
			$ids,
			$model->getId()
		);

		$requestedIds = [];
		foreach ($ids as $id)
		{
			if ((!is_int($id) && !is_string($id)) || $id === '')
			{
				return false;
			}

			$requestedIds[(string)$id] = true;
		}

		$resolvedIds = [];
		foreach (array_keys($attachmentsCreatedByMap) as $id)
		{
			$resolvedIds[(string)$id] = true;
		}

		if (
			count($requestedIds) !== count($ids)
			|| array_diff_key($requestedIds, $resolvedIds) !== []
			|| array_diff_key($resolvedIds, $requestedIds) !== []
		)
		{
			return false;
		}

		$attachments = $diskFileRepository->getByIds($ids);

		return $accessController->check(
			ActionDictionary::ACTION_TASK_DETACH_FILE,
			$model,
			[
				'attachments' => $attachments->toArray(),
				'attachmentsCreatedByMap' => $attachmentsCreatedByMap,
			]
		);
	}
}
