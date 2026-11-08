<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter;

use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessResult;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkBulkAccessService;
use Bitrix\Disk\Type\ObjectCollection;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\ObjectNotFoundException;

class UnifiedLinkBulkAccessChecker extends Base
{
	protected readonly UnifiedLinkBulkAccessService $bulkAccessService;
	protected ?UnifiedLinkAccessResult $accessResult = null;

	/**
	 * @param UnifiedLinkAccessLevel $targetAccessLevel
	 * @param string $argumentName
	 * @param string $parentArgumentName
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public function __construct(
		protected readonly UnifiedLinkAccessLevel $targetAccessLevel,
		protected readonly string $argumentName = 'unifiedObjectCollection',
		protected readonly string $parentArgumentName = 'parentFolder',
	)
	{
		parent::__construct();

		$this->bulkAccessService = ServiceLocator::getInstance()->get(UnifiedLinkBulkAccessService::class);
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$this->accessResult = null;

		$arguments = $this->getAction()->getArguments();
		$parentFolder = $arguments[$this->parentArgumentName] ?? null;
		$unifiedObjectCollection = $arguments[$this->argumentName] ?? null;

		if (
			!$parentFolder instanceof Folder
			|| !$unifiedObjectCollection instanceof ObjectCollection
		)
		{
			$this->addError(new Error(
				message: 'invalid arguments',
				code: 'INVALID_ARGUMENTS',
			));

			return new EventResult(
				type: EventResult::ERROR,
				handler: $this,
			);
		}

		$result = $this->bulkAccessService->check(
			parentFolder: $parentFolder,
			unifiedObjectCollection: $unifiedObjectCollection,
			requiredAccessLevel: $this->targetAccessLevel,
			userId: (int)CurrentUser::get()->getId(),
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return new EventResult(
				type: EventResult::ERROR,
				handler: $this,
			);
		}

		$data = $result->getData();
		$accessResult = $data['accessResult'] ?? null;

		if ($accessResult instanceof UnifiedLinkAccessResult)
		{
			$this->accessResult = $accessResult;
		}

		return null;
	}

	public function getAccessResult(): ?UnifiedLinkAccessResult
	{
		return $this->accessResult;
	}
}
