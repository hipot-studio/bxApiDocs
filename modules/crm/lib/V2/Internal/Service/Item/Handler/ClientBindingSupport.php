<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Handler;

use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;

/**
 * Shared part of single and batch handlers that change contact and company bindings.
 * @internal
 */
final class ClientBindingSupport
{
	public const KIND_CONTACTS = 'contacts';
	public const KIND_COMPANIES = 'companies';

	public const MAX_COMPANIES_FOR_CONTACT = 50;

	private const LOCK_TIMEOUT = 10;

	/**
	 * Serializes changes of one owner field. Returns null when the lock is acquired.
	 */
	public function lock(ItemId $owner, string $fieldName): ?Result
	{
		try
		{
			if (!Application::getConnection()->lock(FieldValueLock::getKey($owner, $fieldName), self::LOCK_TIMEOUT))
			{
				return $this->error('The field is being changed by another operation.', 'CRM_FIELD_VALUE_LOCKED');
			}
		}
		catch (\Throwable)
		{
			return $this->error('The field change could not be serialized.', 'CRM_FIELD_VALUE_LOCK_FAILED');
		}

		return null;
	}

	public function unlock(ItemId $owner, string $fieldName): void
	{
		Application::getConnection()->unlock(FieldValueLock::getKey($owner, $fieldName));
	}

	/**
	 * Loads the owner with the bindings of the given kind.
	 *
	 * @throws ObjectNotFoundException
	 */
	public function loadOwner(ItemId $owner, int $userId, string $kind, bool $checkPermissions): Item
	{
		$select = match ($kind)
		{
			self::KIND_CONTACTS => (new ItemSelect(Item::contactBindings))->withContactBindings(),
			self::KIND_COMPANIES => (new ItemSelect(Item::companyBindings))->withCompanyBindings(),
			default => throw new ArgumentException("Unsupported binding kind: {$kind}", 'kind'),
		};

		$provider = ItemProvider::forEntityType($owner->getEntityType());
		if ($checkPermissions)
		{
			$provider = $provider->withAccessCheck($userId);
		}

		$item = $provider->getById($owner->getId(), $select);
		if ($item === null)
		{
			throw new ObjectNotFoundException("Item not found: {$owner->getId()}");
		}

		return $item;
	}

	/**
	 * Returns the ids of existing child items in input order, without duplicates.
	 * Contacts and companies from a non-default category are treated as missing.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public function filterExisting(EntityType $child, array $ids, int $userId, bool $checkPermissions): array
	{
		$ids = array_values(array_unique(array_filter(
			$ids,
			static fn(mixed $id): bool => is_int($id) && $id > 0,
		)));
		if (empty($ids))
		{
			return [];
		}

		$filter = ['@id' => $ids];
		if ($child->equals(EntityType::contact()) || $child->equals(EntityType::company()))
		{
			$filter['categoryId'] = 0;
		}

		try
		{
			$provider = ItemProvider::forEntityType($child);
			if ($checkPermissions)
			{
				$provider = $provider->withAccessCheck($userId);
			}

			$query = $provider->createIdQuery(new ItemFilter($filter));
			if ($query === null)
			{
				return [];
			}

			$found = [];
			foreach ($query->fetchAll() as $row)
			{
				$found[(int)$row['ID']] = true;
			}
		}
		catch (\Throwable)
		{
			return [];
		}

		return array_values(array_filter($ids, static fn(int $id): bool => isset($found[$id])));
	}

	private function error(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
