<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter;

use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessResult;
use Bitrix\Disk\Type\ObjectCollection;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class DirectOnlyCollectionReadAccessChecker extends Base
{
	public function __construct(
		protected readonly UnifiedLinkBulkAccessChecker $bulkAccessChecker,
		protected readonly string $parentArgumentName = 'parentFolder',
		protected readonly string $argumentName = 'unifiedObjectCollection',
	)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$accessResult = $this->bulkAccessChecker->getAccessResult();

		if (!$accessResult instanceof UnifiedLinkAccessResult)
		{
			$this->addError(new Error(
				message: 'Could not resolve unified link access result.',
				code: 'ACCESS_DENIED',
			));

			return new EventResult(
				type: EventResult::ERROR,
				handler: $this,
			);
		}

		if (!$accessResult->isOnlyPermissionSystemAccess())
		{
			return null;
		}

		$arguments = $this->getAction()->getArguments();
		$parentFolder = $arguments[$this->parentArgumentName] ?? null;
		$unifiedObjectCollection = $arguments[$this->argumentName] ?? null;

		if (
			!$parentFolder instanceof Folder
			|| !$unifiedObjectCollection instanceof ObjectCollection
		)
		{
			$this->addError(new Error(
				message: 'Invalid arguments.',
				code: 'INVALID_ARGUMENTS',
			));

			return new EventResult(
				type: EventResult::ERROR,
				handler: $this,
			);
		}

		$userId = (int)CurrentUser::get()->getId();
		$securityContext = $parentFolder->getStorage()?->getSecurityContext($userId);

		if (!$securityContext)
		{
			$this->addError(new Error(
				message: 'Access denied.',
				code: 'ACCESS_DENIED',
			));

			return new EventResult(
				type: EventResult::ERROR,
				handler: $this,
			);
		}

		$parentFolder->preloadOperationsForSpecifiedObjects(
			ids: $unifiedObjectCollection->getIds(),
			securityContext: $securityContext,
		);

		foreach ($unifiedObjectCollection as $object)
		{
			if (!$object->canRead($securityContext))
			{
				$this->addError(new Error(
					message: 'Access denied.',
					code: 'ACCESS_DENIED',
				));

				return new EventResult(
					type: EventResult::ERROR,
					handler: $this,
				);
			}
		}

		return null;
	}
}
