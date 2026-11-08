<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Handler;

use Bitrix\Crm\Field;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldDescriptor;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\File\FileCustomFieldWriteService;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\ItemOperationRunner;
use Bitrix\Crm\V2\Public\Command\Item\DeleteFieldValueCommand;
use Bitrix\Crm\V2\Public\Command\Item\UpdateItemCommand;
use Bitrix\Crm\V2\Public\Entity\File;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBinding;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBinding;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBindingCollection;
use Bitrix\Crm\V2\Public\Entity\Item\EmailCollection;
use Bitrix\Crm\V2\Public\Entity\Item\EmailValue;
use Bitrix\Crm\V2\Public\Entity\Item\AbstractMultifieldValue;
use Bitrix\Crm\V2\Public\Entity\Item\ImCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ImValue;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneCollection;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneValue;
use Bitrix\Crm\V2\Public\Entity\Item\WebCollection;
use Bitrix\Crm\V2\Public\Entity\Item\WebValue;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Main\Error;
use Bitrix\Main\Application;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * Removes all occurrences of one canonical value from a multiple CRM field.
 * @internal
 */
final class DeleteFieldValueCommandHandler
{
	private const MULTIFIELDS = [
		'phone' => [
			'itemField' => Item::phones,
			'collection' => PhoneCollection::class,
			'value' => PhoneValue::class,
			'getter' => 'getPhones',
			'setter' => 'setPhones',
			'selector' => 'withPhones',
		],
		'email' => [
			'itemField' => Item::emails,
			'collection' => EmailCollection::class,
			'value' => EmailValue::class,
			'getter' => 'getEmails',
			'setter' => 'setEmails',
			'selector' => 'withEmails',
		],
		'web' => [
			'itemField' => Item::webs,
			'collection' => WebCollection::class,
			'value' => WebValue::class,
			'getter' => 'getWebs',
			'setter' => 'setWebs',
			'selector' => 'withWebs',
		],
		'im' => [
			'itemField' => Item::ims,
			'collection' => ImCollection::class,
			'value' => ImValue::class,
			'getter' => 'getIms',
			'setter' => 'setIms',
			'selector' => 'withIms',
		],
	];

	private readonly ItemOperationRunner $operationRunner;
	private readonly CustomFieldRegistry $customFieldRegistry;
	private readonly FileCustomFieldWriteService $fileWriteService;

	public function __construct(
		?ItemOperationRunner $operationRunner = null,
		?CustomFieldRegistry $customFieldRegistry = null,
		?FileCustomFieldWriteService $fileWriteService = null,
	)
	{
		$this->operationRunner = $operationRunner ?? new ItemOperationRunner();
		$this->customFieldRegistry = $customFieldRegistry ?? CustomFieldRegistry::getInstance();
		$this->fileWriteService = $fileWriteService ?? new FileCustomFieldWriteService();
	}

	public function handle(DeleteFieldValueCommand $command): Result
	{
		$target = $this->resolveTarget($command);
		if ($target instanceof Result)
		{
			return $target;
		}

		$lockKey = $this->getLockKey($command->getItemId(), $target['fieldName']);
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
			$item = $this->loadItem(
				$command->getItemId(),
				$command->getUserId(),
				$target,
				$command->shouldCheckPermissions(),
			);
			$updateCommand = (new UpdateItemCommand($item, $command->getUserId()))
				->setScope($command->getScope())
				->withoutPermissionCheck(!$command->shouldCheckPermissions())
				->withoutRequiredUserFieldsCheck(!$command->shouldCheckRequiredUserFields())
				->withoutAutomation(!$command->shouldRunAutomation())
			;
			$preparedOperation = $this->operationRunner->prepare($updateCommand);
			$accessResult = $preparedOperation->checkAccess();
			if (!$accessResult->isSuccess())
			{
				return $accessResult;
			}
			$relationValidation = $this->validateRelationTarget(
				$target,
				$command->getFieldValueElement()->getFieldValue(),
				$item,
			);
			if ($relationValidation instanceof Result)
			{
				return $relationValidation;
			}

			$removed = match ($target['kind'])
			{
				'multifield' => $this->removeMultifield($item, $target, $command->getFieldValueElement()->getFieldValue()),
				'contacts' => $this->removeContact($item, $command->getFieldValueElement()->getFieldValue()),
				'companies' => $this->removeCompany($item, $command->getFieldValueElement()->getFieldValue()),
				'observers' => $this->removeObserver($item, $command->getFieldValueElement()->getFieldValue()),
				'custom' => $this->removeCustomField(
					$item,
					$target,
					$command->getFieldValueElement()->getFieldValue(),
					$command->shouldCheckRequiredUserFields(),
				),
			};

			if ($removed === false)
			{
				return $this->error('The field value was not found.', 'CRM_FIELD_VALUE_NOT_FOUND');
			}
			if ($removed instanceof Result)
			{
				return $removed;
			}
			$categoryResult = $this->operationRunner->validateChangedFields(
				$updateCommand,
				$preparedOperation->getLegacyItem(),
			);
			if (!$categoryResult->isSuccess())
			{
				return $categoryResult;
			}

			if ($target['kind'] === 'custom' && $target['descriptor']->type === Field::TYPE_FILE)
			{
				return $this->fileWriteService->execute(
					$updateCommand,
					[new FileFieldWriteRequest($target['fieldName'], true, $removed)],
					$preparedOperation,
				);
			}

			$preparedOperation->syncChangedFieldsFromV2Item();

			return $preparedOperation->launch();
		}
		finally
		{
			Application::getConnection()->unlock($lockKey);
		}
	}

	/**
	 * @return array<string, mixed>|Result
	 */
	private function resolveTarget(DeleteFieldValueCommand $command): array|Result
	{
		$fieldName = $command->getFieldValueElement()->getFieldName();
		$settings = EntityTypeSettings::of($command->getItemId()->getEntityType());

		if (isset(self::MULTIFIELDS[$fieldName]) && $settings->hasMultifields())
		{
			return [
				'kind' => 'multifield',
				'fieldName' => $fieldName,
				'itemField' => self::MULTIFIELDS[$fieldName]['itemField'],
				'config' => self::MULTIFIELDS[$fieldName],
			];
		}
		if ($fieldName === 'contactsId' && $settings->hasContactBindings())
		{
			return ['kind' => 'contacts', 'fieldName' => $fieldName, 'itemField' => Item::contactBindings];
		}
		if ($fieldName === 'companiesId' && $settings->hasCompanyBindings())
		{
			return ['kind' => 'companies', 'fieldName' => $fieldName, 'itemField' => Item::companyBindings];
		}
		if ($fieldName === 'observersId' && $settings->hasObservers())
		{
			return ['kind' => 'observers', 'fieldName' => $fieldName, 'itemField' => Item::observers];
		}

		$descriptors = $this->customFieldRegistry->getEntityDescriptorsMap($command->getItemId()->getEntityType());
		$descriptor = $descriptors[$fieldName] ?? null;
		if ($descriptor instanceof CustomFieldDescriptor)
		{
			if (in_array($fieldName, $this->customFieldRegistry->getHiddenFieldNames(
				$command->getItemId()->getEntityType(),
				$command->getUserId(),
			), true))
			{
				return $this->error('The field is not supported.', 'CRM_FIELD_NOT_SUPPORTED');
			}
			if (!$descriptor->isMultiple || $descriptor->type === 'boolean')
			{
				return $this->error('Only multiple fields are supported.', 'CRM_FIELD_NOT_MULTIPLE');
			}

			return [
				'kind' => 'custom',
				'fieldName' => $fieldName,
				'itemField' => $fieldName,
				'descriptor' => $descriptor,
			];
		}

		return $this->error('The field is not supported.', 'CRM_FIELD_NOT_SUPPORTED');
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private function loadItem(ItemId $itemId, int $userId, array $target, bool $checkPermissions): Item
	{
		$select = new ItemSelect($target['itemField']);
		if ($target['kind'] === 'multifield')
		{
			$selector = $target['config']['selector'];
			$select->{$selector}();
		}
		elseif ($target['kind'] === 'contacts')
		{
			$select->withContactBindings();
		}
		elseif ($target['kind'] === 'companies')
		{
			$select->withCompanyBindings();
		}
		elseif ($target['kind'] === 'observers')
		{
			$select->withObservers();
		}

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

	/** @param array<string, mixed> $target */
	private function validateRelationTarget(
		array $target,
		mixed $value,
		Item $item,
	): ?Result
	{
		if ($target['kind'] !== 'companies')
		{
			return null;
		}

		$companyBindings = $item->getCompanyBindings();
		$exists = is_int($value)
			&& $value > 0
			&& $companyBindings?->filter(
				static fn(CompanyBinding $binding): bool => $binding->getCompanyId() === $value,
			)->isEmpty() === false
		;

		return $exists ? null : $this->error('The relation value does not exist.', 'CRM_FIELD_VALUE_INVALID');
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private function removeMultifield(Item $item, array $target, mixed $value): bool|Result
	{
		if (!$value instanceof AbstractMultifieldValue)
		{
			return $this->error('A multifield value requires valueTypeId and value.', 'CRM_FIELD_VALUE_INVALID');
		}

		$config = $target['config'];

		$collection = $item->{$config['getter']}();
		if ($collection === null)
		{
			return false;
		}

		$remaining = new $config['collection']();
		$removed = false;
		foreach ($collection->getAll() as $entry)
		{
			if ($entry->getValueType() === $value->getValueType() && trim($entry->getValue()) === trim($value->getValue()))
			{
				$removed = true;

				continue;
			}
			$remaining->add($entry);
		}
		if ($removed)
		{
			$item->{$config['setter']}($remaining);
		}

		return $removed;
	}

	private function removeContact(Item $item, mixed $value): bool|Result
	{
		if (!is_int($value) || $value <= 0)
		{
			return $this->error('The relation value must be a positive integer.', 'CRM_FIELD_VALUE_INVALID');
		}
		$bindings = $item->getContactBindings();
		if ($bindings === null)
		{
			return false;
		}
		$remaining = new ContactBindingCollection();
		$removed = false;
		foreach ($bindings->getAll() as $binding)
		{
			if ($binding->getContactId() === $value)
			{
				$removed = true;

				continue;
			}
			$remaining->add(new ContactBinding($binding->getContactId(), $binding->getSort(), $binding->isPrimary()));
		}
		if ($removed)
		{
			$item->setContactBindings($remaining);
		}

		return $removed;
	}

	private function removeCompany(Item $item, mixed $value): bool|Result
	{
		if (!is_int($value) || $value <= 0)
		{
			return $this->error('The relation value must be a positive integer.', 'CRM_FIELD_VALUE_INVALID');
		}
		$bindings = $item->getCompanyBindings();
		if ($bindings === null)
		{
			return false;
		}
		$remaining = new CompanyBindingCollection();
		$removed = false;
		foreach ($bindings->getAll() as $binding)
		{
			if ($binding->getCompanyId() === $value)
			{
				$removed = true;

				continue;
			}
			$remaining->add(new CompanyBinding($binding->getCompanyId(), $binding->getSort(), $binding->isPrimary()));
		}
		if ($removed)
		{
			$item->setCompanyBindings($remaining);
		}

		return $removed;
	}

	private function removeObserver(Item $item, mixed $value): bool|Result
	{
		if (!is_int($value) || $value <= 0)
		{
			return $this->error('The relation value must be a positive integer.', 'CRM_FIELD_VALUE_INVALID');
		}
		$observers = $item->getObservers();
		if ($observers === null)
		{
			return false;
		}
		$remaining = [];
		$removed = false;
		foreach ($observers as $observerId)
		{
			if ($observerId === $value)
			{
				$removed = true;

				continue;
			}
			$remaining[] = $observerId;
		}
		if ($removed)
		{
			$item->setObservers($remaining);
		}

		return $removed;
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private function removeCustomField(
		Item $item,
		array $target,
		mixed $value,
		bool $checkRequiredUserFields,
	): bool|array|Result
	{
		$descriptor = $target['descriptor'];
		$canonicalValue = $this->canonicalCustomValue($descriptor, $value);
		if ($canonicalValue === null)
		{
			return $this->error('The custom field value is invalid.', 'CRM_FIELD_VALUE_INVALID');
		}

		$current = $item->getCustomField($target['fieldName']);
		$values = is_array($current) ? $current : ($current === null ? [] : [$current]);
		$remaining = [];
		$removed = false;
		foreach ($values as $entry)
		{
			if ($this->canonicalCustomValue($descriptor, $entry) === $canonicalValue)
			{
				$removed = true;

				continue;
			}
			$remaining[] = $entry;
		}
		if (!$removed)
		{
			return false;
		}
		if ($checkRequiredUserFields && $descriptor->isRequired && $remaining === [])
		{
			return $this->error('The required field cannot be empty.', 'CRM_FIELD_REQUIRED');
		}

		if ($descriptor->type === Field::TYPE_FILE)
		{
			return array_map(static fn(File $file): int => (int)$file->getId(), $remaining);
		}

		$item->setCustomField($target['fieldName'], $remaining);

		return true;
	}

	private function canonicalCustomValue(CustomFieldDescriptor $descriptor, mixed $value): ?string
	{
		if ($value instanceof File)
		{
			return $descriptor->type === Field::TYPE_FILE && $value->getId() !== null
				? (string)$value->getId()
				: null;
		}
		if ($value instanceof ItemId)
		{
			if ($descriptor->type !== 'crm')
			{
				return null;
			}

			return count((array)$descriptor->entityTypesId) === 1
				? (string)$value->getId()
				: \CCrmOwnerTypeAbbr::ResolveByTypeID($value->getEntityType()->getId()) . '_' . $value->getId();
		}
		if ($descriptor->type === Field::TYPE_FILE)
		{
			if (is_array($value))
			{
				if (
					array_is_list($value)
					|| !array_key_exists('id', $value)
					|| array_key_exists('upload', $value)
					|| !is_string($value['id'])
					|| preg_match('/\\A[1-9][0-9]*\\z/', $value['id']) !== 1
				)
				{
					return null;
				}

				return $value['id'];
			}

			return is_int($value) && $value > 0 ? (string)$value : null;
		}

		if ($descriptor->type === 'crm')
		{
			return null;
		}
		if (in_array($descriptor->type, ['date', 'datetime'], true))
		{
			return $this->canonicalDateValue($descriptor->type, $value);
		}

		return match ($descriptor->type)
		{
			'integer', 'employee', 'iblock_section', 'iblock_element', 'enumeration'
				=> is_int($value) ? (string)$value : null,
			'double' => is_int($value) || is_float($value) ? (string)(float)$value : null,
			'boolean' => is_bool($value) ? ($value ? '1' : '0') : null,
			'money', 'address', 'string', 'rich_text', 'url', 'crm_status'
				=> is_string($value) ? $value : null,
			default => null,
		};
	}

	private function canonicalDateValue(string $type, mixed $value): ?string
	{
		if ($type === 'date')
		{
			if ($value instanceof Date)
			{
				return $value->format('Y-m-d');
			}
			return null;
		}

		if ($value instanceof DateTime)
		{
			$value = clone $value;
			$value->setTimezone(new \DateTimeZone(date_default_timezone_get()));

			return $value->format('Y-m-d H:i:s');
		}
		return null;
	}

	private function error(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}

	private function getLockKey(ItemId $itemId, string $fieldName): string
	{
		return FieldValueLock::getKey($itemId, $fieldName);
	}
}
