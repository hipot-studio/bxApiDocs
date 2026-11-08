<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Handler;

use Bitrix\Crm\Field;
use Bitrix\Crm\V2\Internal\Integration\IBlock\IBlockDictionary;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldDescriptor;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\CustomFieldEnumDictionary;
use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\StatusDictionary;
use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\UserDictionary;
use Bitrix\Crm\V2\Internal\Service\Item\File\FileCustomFieldWriteService;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileFieldWriteRequest;
use Bitrix\Crm\V2\Internal\Service\Item\File\Value\FileUploadInput;
use Bitrix\Crm\V2\Internal\Service\Item\Operation\ItemOperationRunner;
use Bitrix\Crm\V2\Public\Command\Item\AddFieldValueCommand;
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
use Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement\FileUploadValue;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * Adds one value to a supported multiple CRM field.
 * @internal
 */
final class AddFieldValueCommandHandler
{
	private const MAX_VALUES_PER_FIELD = 50;
	private const MAX_VALUES_TOTAL = 100;
	private const MAX_COMPANIES_FOR_CONTACT = 50;

	private const MULTIFIELDS = [
		'phone' => [
			'itemField' => Item::phones,
			'collection' => PhoneCollection::class,
			'value' => PhoneValue::class,
			'getter' => 'getPhones',
			'setter' => 'setPhones',
		],
		'email' => [
			'itemField' => Item::emails,
			'collection' => EmailCollection::class,
			'value' => EmailValue::class,
			'getter' => 'getEmails',
			'setter' => 'setEmails',
		],
		'web' => [
			'itemField' => Item::webs,
			'collection' => WebCollection::class,
			'value' => WebValue::class,
			'getter' => 'getWebs',
			'setter' => 'setWebs',
		],
		'im' => [
			'itemField' => Item::ims,
			'collection' => ImCollection::class,
			'value' => ImValue::class,
			'getter' => 'getIms',
			'setter' => 'setIms',
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

	public function handle(AddFieldValueCommand $command): Result
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
			$preparedOperation = $this->operationRunner->prepare(
				$updateCommand,
				$this->getOperationFields($target, $command->getItemId()->getEntityType()),
			);
			$accessResult = $preparedOperation->checkAccess();
			if (!$accessResult->isSuccess())
			{
				return $accessResult;
			}
			$relationValidation = $this->validateRelationTarget(
				$target,
				$command->getFieldValueElement()->getFieldValue(),
				$command->getUserId(),
				$command->shouldCheckPermissions(),
				$command->getItemId()->getEntityType(),
			);
			if ($relationValidation instanceof Result)
			{
				return $relationValidation;
			}

			if ($target['kind'] === 'custom' && $target['descriptor']->type === Field::TYPE_FILE)
			{
				$request = $this->buildFileWriteRequest($item, $target, $command->getFieldValueElement()->getFieldValue());
				if ($request instanceof Result)
				{
					return $request;
				}

				return $this->fileWriteService->execute($updateCommand, [$request], $preparedOperation);
			}

			$appendResult = $this->appendValue(
				$item,
				$target,
				$command->getFieldValueElement()->getFieldValue(),
				$command->getUserId(),
				$command->shouldCheckPermissions(),
			);
			if (!$appendResult->isSuccess())
			{
				return $appendResult;
			}
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

	/**
	 * @return array<string, mixed>|Result
	 */
	private function resolveTarget(AddFieldValueCommand $command): array|Result
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

		$descriptor = $this->customFieldRegistry
			->getEntityDescriptorsMap($command->getItemId()->getEntityType())[$fieldName] ?? null
		;
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
			$select->withMultifields();
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

	/**
	 * @param array<string, mixed> $target
	 * @return string[]
	 */
	private function getOperationFields(array $target, EntityType $entityType): array
	{
		$fields = $target['kind'] === 'custom' && $target['descriptor']->type === Field::TYPE_FILE
			? ['ID', $target['fieldName']]
			: ['ID'];

		if (EntityTypeSettings::of($entityType)->isCategoriesSupported())
		{
			$fields[] = 'CATEGORY_ID';
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private function appendValue(
		Item $item,
		array $target,
		mixed $value,
		int $userId,
		bool $checkPermissions,
		?EntityType $entityType = null,
	): Result
	{
		return match ($target['kind'])
		{
			'multifield' => $this->appendMultifield($item, $target, $value),
			'contacts' => $this->appendContact($item, $value),
			'companies' => $this->appendCompany($item, $value, $entityType),
			'observers' => $this->appendObserver($item, $value),
			'custom' => $this->appendCustomField($item, $target, $value, $userId, $checkPermissions),
		};
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private function appendMultifield(Item $item, array $target, mixed $value): Result
	{
		if (!$value instanceof AbstractMultifieldValue)
		{
			return $this->error('A multifield value requires valueTypeId and value.', 'CRM_FIELD_VALUE_INVALID');
		}

		$config = $target['config'];
		$collection = new $config['collection']();
		$current = $item->{$config['getter']}();
		foreach ($current?->getAll() ?? [] as $entry)
		{
			if ($entry->getValueType() === $value->getValueType() && trim($entry->getValue()) === trim($value->getValue()))
			{
				return $this->error('The field value already exists.', 'CRM_FIELD_VALUE_DUPLICATE');
			}

			$collection->add($this->copyMultifieldValue($entry, $config['value']));
		}
		$collection->add($value);
		if (count($collection->getAll()) > self::MAX_VALUES_PER_FIELD)
		{
			return $this->error('Too many multifield values.', 'CRM_FIELD_VALUE_LIMIT_EXCEEDED');
		}
		if ($this->getMultifieldTotal($item) + 1 > self::MAX_VALUES_TOTAL)
		{
			return $this->error('Too many multifield values.', 'CRM_FIELD_VALUE_LIMIT_EXCEEDED');
		}
		$item->{$config['setter']}($collection);

		return new Result();
	}

	private function appendContact(Item $item, mixed $value): Result
	{
		if (!is_int($value) || $value <= 0)
		{
			return $this->error('The relation value must be a positive integer.', 'CRM_FIELD_VALUE_INVALID');
		}

		$bindings = new ContactBindingCollection();
		$sort = 0;
		foreach ($item->getContactBindings()?->getAll() ?? [] as $binding)
		{
			if ($binding->getContactId() === $value)
			{
				return $this->error('The field value already exists.', 'CRM_FIELD_VALUE_DUPLICATE');
			}

			$bindings->add(new ContactBinding($binding->getContactId(), $binding->getSort(), $binding->isPrimary()));
			$sort = max($sort, $binding->getSort() + 1);
		}
		$bindings->add(new ContactBinding($value, $sort, $bindings->isEmpty()));
		$item->setContactBindings($bindings);

		return new Result();
	}

	private function appendCompany(Item $item, mixed $value, ?EntityType $entityType = null): Result
	{
		if (!is_int($value) || $value <= 0)
		{
			return $this->error('The relation value must be a positive integer.', 'CRM_FIELD_VALUE_INVALID');
		}

		$bindings = new CompanyBindingCollection();
		$sort = 0;
		foreach ($item->getCompanyBindings()?->getAll() ?? [] as $binding)
		{
			if ($binding->getCompanyId() === $value)
			{
				return $this->error('The field value already exists.', 'CRM_FIELD_VALUE_DUPLICATE');
			}

			$bindings->add(new CompanyBinding($binding->getCompanyId(), $binding->getSort(), $binding->isPrimary()));
			$sort = max($sort, $binding->getSort() + 1);
		}
		if (($entityType === null || $entityType->equals(EntityType::contact())) && count($bindings) >= self::MAX_COMPANIES_FOR_CONTACT)
		{
			return $this->error('Too many company bindings.', 'CRM_FIELD_VALUE_LIMIT_EXCEEDED');
		}
		$bindings->add(new CompanyBinding($value, $sort, $bindings->isEmpty()));
		$item->setCompanyBindings($bindings);

		return new Result();
	}

	private function appendObserver(Item $item, mixed $value): Result
	{
		if (!is_int($value) || $value <= 0)
		{
			return $this->error('The relation value must be a positive integer.', 'CRM_FIELD_VALUE_INVALID');
		}

		$observers = $item->getObservers() ?? [];
		if (in_array($value, $observers, true))
		{
			return $this->error('The field value already exists.', 'CRM_FIELD_VALUE_DUPLICATE');
		}

		$observers[] = $value;
		$item->setObservers($observers);

		return new Result();
	}

	/**
	 * @param array<string, mixed> $target
	 */
	private function appendCustomField(
		Item $item,
		array $target,
		mixed $value,
		int $userId,
		bool $checkPermissions,
	): Result
	{
		if ($value === null)
		{
			return $this->error('The custom field value is invalid.', 'CRM_FIELD_VALUE_INVALID');
		}

		$normalized = $this->validatePreparedCustomValue(
			$target['descriptor'],
			$value,
			$userId,
			$checkPermissions,
		);
		if ($normalized instanceof Result)
		{
			return $normalized;
		}

		$current = $item->getCustomField($target['fieldName']);
		$values = is_array($current) ? $current : ($current === null ? [] : [$current]);
		$canonicalValue = $this->canonicalCustomValue($target['descriptor'], $normalized);
		foreach ($values as $currentValue)
		{
			if ($this->canonicalCustomValue($target['descriptor'], $currentValue) === $canonicalValue)
			{
				return $this->error('The field value already exists.', 'CRM_FIELD_VALUE_DUPLICATE');
			}
		}

		$values[] = $normalized;
		$item->setCustomField($target['fieldName'], $values);

		return new Result();
	}

	private function validatePreparedCustomValue(
		CustomFieldDescriptor $descriptor,
		mixed $value,
		int $userId,
		bool $checkPermissions,
	): mixed
	{
		$isValid = match ($descriptor->type)
		{
			'integer', 'enumeration', 'employee', 'iblock_section', 'iblock_element' => is_int($value),
			'double' => is_int($value) || is_float($value),
			'string', 'rich_text', 'url', 'crm_status', 'money', 'address' => is_string($value),
			'boolean' => is_bool($value),
			'date' => $value instanceof Date,
			'datetime' => $value instanceof DateTime,
			'crm' => $value instanceof ItemId,
			default => true,
		};

		if (!$isValid)
		{
			return $this->error('The custom field value is invalid.', 'CRM_FIELD_VALUE_INVALID');
		}
		if (!$this->isValidDictionaryValue($descriptor, $value, $userId, $checkPermissions))
		{
			return $this->error('The custom field value is invalid.', 'CRM_FIELD_VALUE_INVALID');
		}
		if ($descriptor->type === 'crm' && $value instanceof ItemId)
		{
			$entityTypeId = $value->getEntityType()->getId();
			if (
				!in_array($entityTypeId, (array)$descriptor->entityTypesId, true)
				|| !$this->crmItemExists($userId, $entityTypeId, $value->getId(), $checkPermissions)
			)
			{
				return $this->error('The relation value does not exist.', 'CRM_FIELD_VALUE_INVALID');
			}
		}
		if ($descriptor->type === 'datetime' && $value instanceof DateTime)
		{
			$value = clone $value;
			$value->setTimezone(new \DateTimeZone(date_default_timezone_get()));
		}

		return $value;
	}

	private function isValidDictionaryValue(
		CustomFieldDescriptor $descriptor,
		mixed $value,
		int $userId,
		bool $checkPermissions,
	): bool
	{
		return match ($descriptor->type)
		{
			'enumeration' => in_array($value, (new CustomFieldEnumDictionary())->getEnumIds($descriptor->id), true),
			'employee' => in_array(
				$value,
				(new UserDictionary())->getAvailableObserverUserIds($userId, [$value], $checkPermissions),
				true,
			),
			'crm_status' => $descriptor->statusTypeId !== null
				&& in_array(
					$value,
					(new StatusDictionary())->getAllIdByStatusTypeId($descriptor->statusTypeId),
					true,
				),
			'iblock_element' => in_array(
				$value,
				(new IBlockDictionary())->getElementsId($descriptor->iBlockId ?? 0, [$value]),
				true,
			),
			'iblock_section' => in_array(
				$value,
				(new IBlockDictionary())->getSectionsId($descriptor->iBlockId ?? 0, [$value]),
				true,
			),
			default => true,
		};
	}

	/** @param array<string, mixed> $target */
	private function validateRelationTarget(
		array $target,
		mixed $value,
		int $userId,
		bool $checkPermissions,
	): ?Result
	{
		$entityTypeId = match ($target['kind'])
		{
			'contacts' => EntityType::contact()->getId(),
			'companies' => EntityType::company()->getId(),
			'observers' => null,
			default => null,
		};
		if ($target['kind'] === 'observers')
		{
			$exists = is_int($value)
				&& $value > 0
				&& in_array(
					$value,
					(new UserDictionary())->getAvailableObserverUserIds($userId, [$value], $checkPermissions),
					true,
				);
		}
		elseif ($entityTypeId !== null)
		{
			$exists = is_int($value)
				&& $value > 0
				&& $this->crmItemExists($userId, $entityTypeId, $value, $checkPermissions)
			;
		}
		else
		{
			return null;
		}

		return $exists ? null : $this->error('The relation value does not exist.', 'CRM_FIELD_VALUE_INVALID');
	}

	private function crmItemExists(int $userId, int $entityTypeId, int $itemId, bool $checkPermissions): bool
	{
		try
		{
			$entityType = EntityType::fromId($entityTypeId);
			$provider = ItemProvider::forEntityType($entityType);
			if ($checkPermissions)
			{
				$provider = $provider->withAccessCheck($userId);
			}

			$categoryRestricted = in_array(
				$entityTypeId,
				[EntityType::company()->getId(), EntityType::contact()->getId()],
				true,
			);
			$select = $categoryRestricted ? ['id', 'categoryId'] : ['id'];
			$item = $provider->getById($itemId, $select);

			return $item !== null
				&& (!$categoryRestricted || $item->getCategoryId() === 0)
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function copyMultifieldValue(object $entry, string $class): object
	{
		$id = method_exists($entry, 'getId') ? $entry->getId() : null;
		if ($entry instanceof PhoneValue)
		{
			return new $class($entry->getValueType(), $entry->getValue(), $id, $entry->getCountryCode());
		}

		return new $class($entry->getValueType(), $entry->getValue(), $id);
	}

	private function canonicalCustomValue(CustomFieldDescriptor $descriptor, mixed $value): string
	{
		if ($value instanceof ItemId)
		{
			return 'item:' . $value->getEntityType()->getId() . ':' . $value->getId();
		}
		if ($value instanceof File)
		{
			return 'file:' . (string)$value->getId();
		}
		if ($value instanceof DateTime)
		{
			$dateTime = clone $value;
			$dateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));

			return 'datetime:' . $dateTime->format('Y-m-d H:i:s');
		}
		if ($value instanceof Date)
		{
			return 'date:' . $value->format('Y-m-d');
		}

		return $descriptor->type . ':' . (is_bool($value) ? (int)$value : (string)$value);
	}

	private function getMultifieldTotal(Item $item): int
	{
		$total = 0;
		foreach (['getPhones', 'getEmails', 'getWebs', 'getIms'] as $getter)
		{
			$total += count($item->{$getter}()?->getAll() ?? []);
		}

		return $total;
	}

	/** @param array<string, mixed> $target */
	private function buildFileWriteRequest(Item $item, array $target, mixed $value): FileFieldWriteRequest|Result
	{
		$current = $item->getCustomField($target['fieldName']);
		$currentValues = is_array($current) ? $current : ($current === null ? [] : [$current]);
		$currentIds = [];
		foreach ($currentValues as $currentValue)
		{
			$id = $currentValue instanceof File ? $currentValue->getId() : (is_int($currentValue) ? $currentValue : null);
			if ($id === null || $id <= 0)
			{
				return $this->error('The current file value is invalid.', 'CRM_FIELD_VALUE_INVALID');
			}
			$currentIds[] = $id;
		}

		if ($value instanceof File)
		{
			$value = $value->getId();
		}
		if (is_int($value))
		{
			if (in_array($value, $currentIds, true))
			{
				return $this->error('The field value already exists.', 'CRM_FIELD_VALUE_DUPLICATE');
			}

			return new FileFieldWriteRequest($target['fieldName'], true, [...$currentIds, $value]);
		}

		if (!$value instanceof FileUploadValue)
		{
			return $this->error('The file value is invalid.', 'CRM_FIELD_VALUE_INVALID');
		}

		$input = new FileUploadInput(
			$value->getName(),
			$value->getData(),
			$value->getUrl(),
			$value->getPath(),
		);

		return new FileFieldWriteRequest($target['fieldName'], true, [...$currentIds, $input]);
	}

	private function getLockKey(ItemId $itemId, string $fieldName): string
	{
		return FieldValueLock::getKey($itemId, $fieldName);
	}

	private function error(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
