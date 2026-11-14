<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Handler;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Item\Binding\PrimaryContactSupport;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\ItemOperationRunner;
use Bitrix\Crm\V2\Public\Command\Item\SetPrimaryContactCommand;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBinding;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

/**
 * Moves the primary flag to one of the contacts already bound to the item.
 *
 * Shares the lock key with {@see AddFieldValueCommandHandler}: both methods change the bindings of a
 * single item and must queue on the same key.
 * @internal
 */
final class SetPrimaryContactCommandHandler
{
	private const FIELD_NAME = 'contactsId';

	private const LOGGER_ID = 'Default';

	private readonly ItemOperationRunner $operationRunner;

	public function __construct(
		?ItemOperationRunner $operationRunner = null,
		private readonly ?LoggerInterface $logger = null,
	)
	{
		$this->operationRunner = $operationRunner ?? new ItemOperationRunner();
	}

	public function handle(SetPrimaryContactCommand $command): Result
	{
		$itemId = $command->getItemId();
		if (!PrimaryContactSupport::isSupported($itemId->getEntityType()))
		{
			return $this->error('The field is not supported.', 'CRM_FIELD_NOT_SUPPORTED');
		}

		$lockKey = FieldValueLock::getKey($itemId, self::FIELD_NAME);
		try
		{
			if (!Application::getConnection()->lock($lockKey, 10))
			{
				return $this->error('The field is being changed by another operation.', 'CRM_FIELD_VALUE_LOCKED');
			}
		}
		catch (\Throwable)
		{
			return $this->error('The field change could not be serialized.', 'CRM_FIELD_VALUE_LOCK_FAILED');
		}

		try
		{
			$item = $this->loadItem($itemId, $command->getUserId(), $command->shouldCheckPermissions());
			$updateCommand = (new UpdateItemCommand($item, $command->getUserId()))
				->setScope($command->getScope())
				->withoutPermissionCheck(!$command->shouldCheckPermissions())
				->withoutRequiredUserFieldsCheck(!$command->shouldCheckRequiredUserFields())
				->withoutAutomation(!$command->shouldRunAutomation())
			;
			$preparedOperation = $this->operationRunner->prepare(
				$updateCommand,
				$this->getOperationFields($itemId->getEntityType()),
			);
			$accessResult = $preparedOperation->checkAccess();
			if (!$accessResult->isSuccess())
			{
				return $accessResult;
			}
			if (!$this->contactIsReadable(
				$command->getUserId(),
				$command->getContactId(),
				$command->shouldCheckPermissions(),
			))
			{
				return $this->error('The contact does not exist.', 'CONTACT_NOT_FOUND');
			}

			$current = $item->getContactBindings() ?? new ContactBindingCollection();
			if (!$this->isBound($current, $command->getContactId()))
			{
				return $this->error('The contact is not bound to the item.', 'CONTACT_NOT_BOUND');
			}
			if ($current->getPrimary()?->getContactId() === $command->getContactId())
			{
				return new Result();
			}

			$item->setContactBindings($this->withPrimary($current, $command->getContactId()));
			$categoryResult = $this->operationRunner->validateChangedFields(
				$updateCommand,
				$preparedOperation->getLegacyItem(),
			);
			if (!$categoryResult->isSuccess())
			{
				return $categoryResult;
			}

			$preparedOperation->syncChangedFieldsFromV2Item();

			return $preparedOperation->launchInTransaction();
		}
		finally
		{
			Application::getConnection()->unlock($lockKey);
		}
	}

	private function loadItem(ItemId $itemId, int $userId, bool $checkPermissions): Item
	{
		$select = (new ItemSelect(Item::contactBindings))->withContactBindings();

		$provider = ItemProvider::forEntityType($itemId->getEntityType());
		if ($checkPermissions)
		{
			$provider = $provider->withAccessCheck($userId);
		}

		$item = $provider->getById($itemId->getId(), $select);
		if ($item === null)
		{
			throw new ObjectNotFoundException("Item not found: {$itemId->getId()}");
		}

		return $item;
	}

	/**
	 * @return string[]
	 */
	private function getOperationFields(EntityType $entityType): array
	{
		$fields = ['ID'];
		if (EntityTypeSettings::of($entityType)->isCategoriesSupported())
		{
			$fields[] = 'CATEGORY_ID';
		}

		return $fields;
	}

	private function contactIsReadable(int $userId, int $contactId, bool $checkPermissions): bool
	{
		if ($contactId <= 0)
		{
			return false;
		}

		try
		{
			$provider = ItemProvider::forEntityType(EntityType::contact());
			if ($checkPermissions)
			{
				$provider = $provider->withAccessCheck($userId);
			}

			return $provider->getById($contactId, ['id']) !== null;
		}
		catch (\Throwable $exception)
		{
			// the verdict of the method stays the same even when the check itself fails
			$this->resolveLogger()->error(
				'CRM could not tell whether the contact is readable: {message}',
				[
					'message' => $exception->getMessage(),
					'contactId' => $contactId,
				],
			);

			return false;
		}
	}

	private function resolveLogger(): LoggerInterface
	{
		return $this->logger ?? Container::getInstance()->getLogger(self::LOGGER_ID);
	}

	private function isBound(ContactBindingCollection $bindings, int $contactId): bool
	{
		foreach ($bindings->getAll() as $binding)
		{
			if ($binding->getContactId() === $contactId)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Keeps the composition, the order and the sort of the bindings, and carries an explicit flag on
	 * every one of them: a collection without flags makes the write path promote the first binding.
	 */
	private function withPrimary(ContactBindingCollection $bindings, int $contactId): ContactBindingCollection
	{
		$rebuilt = new ContactBindingCollection();
		foreach ($bindings->getAll() as $binding)
		{
			$rebuilt->add(new ContactBinding(
				$binding->getContactId(),
				$binding->getSort(),
				$binding->getContactId() === $contactId,
			));
		}

		return $rebuilt;
	}

	private function error(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
