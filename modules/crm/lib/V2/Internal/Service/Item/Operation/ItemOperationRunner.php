<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Operation;

use Bitrix\Crm\Item as LegacyItem;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceRebindConflictException;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingSourceInvalidException;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\ItemFieldMapper;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\OperationSettingsMapper;
use Bitrix\Crm\V2\Internal\Service\Item\Mapper\ScopeContextMapper;
use Bitrix\Crm\V2\Internal\Service\ItemCache;
use Bitrix\Crm\V2\Internal\Service\Tracking\ItemSourceAssigner;
use Bitrix\Crm\V2\Public\Command\Item\AbstractItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\AddItemCommand;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class ItemOperationRunner
{
	/** The source of the item is not assignable: it does not exist or is archived. Nothing is written. */
	public const ERROR_TRACKING_SOURCE_INVALID = 'TRACKING_SOURCE_INVALID';
	/**
	 * The item is written, its source is not: customData carries `itemId` and `conflict` (the attribution
	 * of the item was changed by a parallel request).
	 */
	public const ERROR_TRACKING_SOURCE_NOT_APPLIED = 'TRACKING_SOURCE_NOT_APPLIED';

	private readonly Container $container;
	private ?ItemSourceAssigner $itemSourceAssigner;

	public function __construct(?Container $container = null, ?ItemSourceAssigner $itemSourceAssigner = null)
	{
		$this->container = $container ?? Container::getInstance();
		$this->itemSourceAssigner = $itemSourceAssigner;
	}

	/**
	 * A changed tracking source is checked before the item is written and assigned after it: the
	 * attribution lives in its own tables, outside the legacy operation. When nothing but the source is
	 * changed, the legacy operation is not launched, only the right to update the item is checked.
	 */
	public function run(
		AbstractItemCommand $command,
		?ItemOperationObserver $observer = null,
	): Result
	{
		if (
			$command instanceof UpdateItemCommand
			&& !$command->getItem()->hasChangedFields()
		)
		{
			return new Result();
		}

		return $this->runWithTrackingSource(
			$command,
			function (?PreparedItemOperation $preparedOperation) use ($command, $observer): Result
			{
				$preparedOperation ??= $this->prepareObserved($command, $observer);
				if ($preparedOperation instanceof Result)
				{
					return $preparedOperation;
				}

				return $preparedOperation->launch($observer);
			},
			false,
			$observer,
		);
	}

	/**
	 * Wraps a write of the item in the handling of its tracking source, for every way the item is written.
	 *
	 * Once the source is given, the right to write the item is checked first. A source equal to the current
	 * source of the item is then no change; another one is checked before the write and assigned after a
	 * successful write.
	 *
	 * @param callable(?PreparedItemOperation): Result $write writes the item. It gets the operation the
	 * runner has prepared and whose access it has checked, or null when no source is given: then the write
	 * prepares and checks the operation on its own.
	 * @param bool $hasOtherChanges the write changes the item beyond its fields, such as its files, so it is
	 * launched even when the source is the only changed field
	 */
	public function runWithTrackingSource(
		AbstractItemCommand $command,
		callable $write,
		bool $hasOtherChanges = false,
		?ItemOperationObserver $observer = null,
	): Result
	{
		$v2Item = $this->findV2Item($command);
		if ($v2Item === null || !$this->isTrackingSourceChanged($v2Item))
		{
			return $write(null);
		}

		$isOnlyTrackingSourceChanged = !$hasOtherChanges
			&& $command instanceof UpdateItemCommand
			&& $v2Item->getChangedFieldNames() === [Item::trackingSourceId]
		;
		$trackingSourceId = $v2Item->getTrackingSourceId();
		$preparedOperation = $this->prepareObserved($command, $observer);
		if ($preparedOperation instanceof Result)
		{
			return $preparedOperation;
		}

		// The right comes first: neither the check of the source nor its comparison with the current one may
		// answer a user who may not write - an answer would tell the current source of the item.
		if ($preparedOperation->getOperation()->isCheckAccessEnabled())
		{
			$accessResult = $preparedOperation->checkAccess();
			if (!$accessResult->isSuccess())
			{
				return $accessResult;
			}
		}

		if ($this->isCurrentTrackingSource($command, $v2Item))
		{
			if ($isOnlyTrackingSourceChanged)
			{
				$v2Item->resetChangedFields();

				return new Result();
			}

			return $write($preparedOperation);
		}

		$checkResult = $this->checkTrackingSource($trackingSourceId);
		if (!$checkResult->isSuccess())
		{
			return $checkResult;
		}

		if ($isOnlyTrackingSourceChanged)
		{
			$v2Item->resetChangedFields();
		}
		else
		{
			$result = $write($preparedOperation);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return $this->assignTrackingSource($v2Item, $trackingSourceId);
	}

	public function prepare(AbstractItemCommand $command, array $fieldsToSelect = ['*']): PreparedItemOperation
	{
		$legacyItem = null;

		return $this->prepareInternal($command, $legacyItem, $fieldsToSelect);
	}

	public function validateChangedFields(AbstractItemCommand $command, LegacyItem $legacyItem): Result
	{
		try
		{
			$this->throwIfChangedFieldDisabledInCategory($command->getItem(), $legacyItem);
		}
		catch (CategoryDependentFieldException $exception)
		{
			return (new Result())->addError($exception->getError());
		}

		return new Result();
	}

	/**
	 * @return PreparedItemOperation|Result the operation, or the refusal of a field disabled in the category
	 */
	private function prepareObserved(
		AbstractItemCommand $command,
		?ItemOperationObserver $observer,
	): PreparedItemOperation|Result
	{
		$legacyItem = null;
		try
		{
			return $this->prepareInternal($command, $legacyItem, ['*']);
		}
		catch (CategoryDependentFieldException $exception)
		{
			return (new Result())->addError($exception->getError());
		}
		catch (\Throwable $throwable)
		{
			$observer?->onPreparationThrowable($legacyItem, $throwable);

			throw $throwable;
		}
	}

	private function prepareInternal(
		AbstractItemCommand $command,
		?LegacyItem &$legacyItem,
		array $fieldsToSelect,
	): PreparedItemOperation
	{
		return match (true)
		{
			$command instanceof AddItemCommand => $this->prepareAdd($command, $legacyItem),
			$command instanceof UpdateItemCommand => $this->prepareUpdate($command, $legacyItem, $fieldsToSelect),
			default => throw new ArgumentException('Unsupported item command'),
		};
	}

	private function prepareAdd(
		AddItemCommand $command,
		?LegacyItem &$preparedLegacyItem,
	): PreparedItemOperation
	{
		$v2Item = $command->getItem();
		$entityTypeId = $v2Item->getEntityType()->getId();
		$factory = $this->getFactory($entityTypeId);
		$legacyItem = $factory->createItem();
		$preparedLegacyItem = $legacyItem;
		$this->throwIfChangedFieldDisabledInCategory($v2Item, $legacyItem);

		ItemFieldMapper::copyToLegacy($v2Item, $legacyItem);

		$context = ScopeContextMapper::createContext($command);
		$operation = $factory->getAddOperation($legacyItem, $context);
		OperationSettingsMapper::applyCommandSettings($operation, $command);

		return new PreparedItemOperation(
			$command,
			$legacyItem,
			$operation,
			$context,
			$this->container->getUserPermissions($command->getUserId()),
		);
	}

	private function prepareUpdate(
		UpdateItemCommand $command,
		?LegacyItem &$preparedLegacyItem,
		array $fieldsToSelect,
	): PreparedItemOperation
	{
		$v2Item = $command->getItem();
		$itemId = $v2Item->getId();
		if ($itemId === null)
		{
			throw new ArgumentException('Cannot update item without ID');
		}

		$entityTypeId = $v2Item->getEntityType()->getId();
		$factory = $this->getFactory($entityTypeId);
		$legacyItem = $factory->getItem($itemId, $fieldsToSelect);
		if ($legacyItem === null)
		{
			throw new ObjectNotFoundException("Item not found: {$entityTypeId}:{$itemId}");
		}
		$preparedLegacyItem = $legacyItem;
		$this->throwIfChangedFieldDisabledInCategory($v2Item, $legacyItem);

		ItemFieldMapper::copyChangedToLegacy($v2Item, $legacyItem);

		$context = ScopeContextMapper::createContext($command);
		$operation = $factory->getUpdateOperation($legacyItem, $context);
		OperationSettingsMapper::applyCommandSettings($operation, $command);

		return new PreparedItemOperation(
			$command,
			$legacyItem,
			$operation,
			$context,
			$this->container->getUserPermissions($command->getUserId()),
		);
	}

	private function findV2Item(AbstractItemCommand $command): ?Item
	{
		return match (true)
		{
			$command instanceof AddItemCommand,
			$command instanceof UpdateItemCommand => $command->getItem(),
			default => null,
		};
	}

	private function isTrackingSourceChanged(Item $v2Item): bool
	{
		return in_array(Item::trackingSourceId, $v2Item->getChangedFieldNames(), true);
	}

	/**
	 * Only an existing item has a current source; it is compared with the stored attribution.
	 */
	private function isCurrentTrackingSource(AbstractItemCommand $command, Item $v2Item): bool
	{
		$itemId = (int)$v2Item->getId();
		if (!$command instanceof UpdateItemCommand || $itemId <= 0)
		{
			return false;
		}

		return $this->getItemSourceAssigner()->isCurrent(
			new ItemIdentifier($v2Item->getEntityType()->getId(), $itemId),
			$v2Item->getTrackingSourceId(),
		);
	}

	private function checkTrackingSource(?int $sourceId): Result
	{
		$result = new Result();
		try
		{
			$this->getItemSourceAssigner()->assertAssignable($sourceId);
		}
		catch (TrackingSourceInvalidException)
		{
			$result->addError(new Error(
				(string)Loc::getMessage('CRM_V2_ITEM_OPERATION_TRACKING_SOURCE_INVALID'),
				self::ERROR_TRACKING_SOURCE_INVALID,
				['field' => Item::trackingSourceId],
			));
		}

		return $result;
	}

	/**
	 * The item is already written here, so a failure is reported with its id: a caller that took it for
	 * a refused write would repeat an add and get a second item.
	 */
	private function assignTrackingSource(Item $v2Item, ?int $sourceId): Result
	{
		$entityType = $v2Item->getEntityType();
		$itemId = (int)$v2Item->getId();
		$isConflict = false;

		try
		{
			$this->getItemSourceAssigner()->assign(new ItemIdentifier($entityType->getId(), $itemId), $sourceId);
		}
		catch (TraceRebindConflictException)
		{
			$isConflict = true;
		}
		catch (\Throwable $throwable)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($throwable);

			return $this->createTrackingSourceNotAppliedResult($itemId, false);
		}
		finally
		{
			ItemCache::getInstance()->invalidate($entityType, $itemId);
		}

		return $isConflict ? $this->createTrackingSourceNotAppliedResult($itemId, true) : new Result();
	}

	private function createTrackingSourceNotAppliedResult(int $itemId, bool $isConflict): Result
	{
		return (new Result())->addError(new Error(
			(string)Loc::getMessage(
				$isConflict
					? 'CRM_V2_ITEM_OPERATION_TRACKING_SOURCE_CONFLICT'
					: 'CRM_V2_ITEM_OPERATION_TRACKING_SOURCE_NOT_APPLIED'
			),
			self::ERROR_TRACKING_SOURCE_NOT_APPLIED,
			[
				'field' => Item::trackingSourceId,
				'itemId' => $itemId,
				'conflict' => $isConflict,
			],
		));
	}

	private function getItemSourceAssigner(): ItemSourceAssigner
	{
		return $this->itemSourceAssigner ??= new ItemSourceAssigner();
	}

	private function throwIfChangedFieldDisabledInCategory(
		Item $v2Item,
		LegacyItem $legacyItem,
	): void
	{
		$fieldName = ItemFieldMapper::findChangedFieldDisabledInCategory($v2Item, $legacyItem);
		if ($fieldName !== null)
		{
			throw new CategoryDependentFieldException($fieldName);
		}
	}

	private function getFactory(int $entityTypeId): Factory
	{
		$factory = $this->container->getFactory($entityTypeId);
		if ($factory === null)
		{
			throw new ObjectNotFoundException("Factory not found for entity type: {$entityTypeId}");
		}

		return $factory;
	}
}
