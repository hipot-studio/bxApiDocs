<?php

declare(strict_types=1);

namespace Bitrix\Lists\Internal\Integration\Crm\Validator;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\UserField\DataModifiers\Element;
use Bitrix\Main\Loader;

final class CrmPropertyValidator
{
	private const ERROR_UNABLE_TO_VALIDATE = 'CRM property value cannot be validated.';
	private const ERROR_NO_SUPPORTED_TYPES = 'CRM property has no supported entity types.';
	private const ERROR_INCORRECT_VALUE = 'CRM property value is not correct.';
	private const ERROR_ITEM_NOT_AVAILABLE = 'CRM property item is not available.';

	private array $property;
	private int $userId;
	private mixed $currentRawValue;

	private array $supportedTypes = [];
	private array $readableTypes = [];
	private array $currentItemsMap = [];
	private ?object $itemPermissions = null;

	private ?string $errorMessage = null;
	private mixed $filteredValue = null;

	public function __construct(array $property, int $userId, mixed $currentRawValue = null)
	{
		$this->property = $property;
		$this->userId = $userId;
		$this->currentRawValue = $currentRawValue;
	}

	public function validate(mixed $rawValue): bool
	{
		$this->errorMessage = null;
		$this->filteredValue = $rawValue;

		if (empty($this->extractValues($rawValue)))
		{
			return true;
		}

		if (!Loader::includeModule('crm'))
		{
			return $this->fail(self::ERROR_UNABLE_TO_VALIDATE);
		}

		if (!$this->initContext())
		{
			return false;
		}

		$this->filteredValue = $this->filterRawValue($rawValue);

		return $this->errorMessage === null;
	}

	public function getErrorMessage(): ?string
	{
		return $this->errorMessage;
	}

	public function getFilteredValue(): mixed
	{
		return $this->filteredValue;
	}

	private function fail(string $message): bool
	{
		$this->setFirstError($message);

		return false;
	}

	private function initContext(): bool
	{
		$this->supportedTypes = Element::getSupportedTypes($this->property['USER_TYPE_SETTINGS'] ?? []);
		if (empty($this->supportedTypes))
		{
			return $this->fail(self::ERROR_NO_SUPPORTED_TYPES);
		}

		$this->readableTypes = $this->getReadableTypes();
		$this->currentItemsMap = $this->getResolvedItemsMap($this->currentRawValue);
		$this->itemPermissions = $this->userId > 0
			? Container::getInstance()->getUserPermissions($this->userId)->item()
			: null
		;

		return true;
	}

	private function getReadableTypes(): array
	{
		if ($this->userId <= 0)
		{
			return [];
		}

		$permissions = Container::getInstance()->getUserPermissions($this->userId)->entityType();

		return array_filter(
			$this->supportedTypes,
			static fn(string $entityTypeName, int $entityTypeId): bool => $permissions->canReadItems($entityTypeId),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	private function extractValues(mixed $rawValue): array
	{
		$values = [];

		if (is_array($rawValue))
		{
			if (array_key_exists('VALUE', $rawValue))
			{
				return $this->extractValues($rawValue['VALUE']);
			}

			foreach ($rawValue as $value)
			{
				foreach ($this->extractValues($value) as $innerValue)
				{
					$values[] = $innerValue;
				}
			}

			return $values;
		}

		if (is_scalar($rawValue))
		{
			$value = trim((string)$rawValue);
			if ($value !== '')
			{
				$values[] = $value;
			}
		}

		return $values;
	}

	private function getResolvedItemsMap(mixed $rawValue): array
	{
		$itemsMap = [];
		foreach ($this->extractValues($rawValue) as $value)
		{
			$item = $this->resolveItem($value);
			if ($item === null)
			{
				continue;
			}

			$itemsMap[$this->getItemKey($item['entityTypeId'], $item['entityId'])] = true;
		}

		return $itemsMap;
	}

	private function filterRawValue(mixed $rawValue, bool $allowOmit = false): mixed
	{
		if (!is_array($rawValue))
		{
			return $this->filterScalarValue($rawValue, $allowOmit);
		}

		if (array_key_exists('VALUE', $rawValue))
		{
			return $this->filterWrappedValue($rawValue, $allowOmit);
		}

		return $this->filterListValue($rawValue);
	}

	private function filterWrappedValue(array $rawValue, bool $allowOmit): ?array
	{
		$filteredValue = $this->filterRawValue($rawValue['VALUE'], $allowOmit);
		if ($allowOmit && $filteredValue === null)
		{
			return null;
		}

		$rawValue['VALUE'] = $filteredValue ?? '';

		return $rawValue;
	}

	private function filterListValue(array $rawValue): array
	{
		$filtered = [];
		foreach ($rawValue as $key => $value)
		{
			$filteredValue = $this->filterRawValue($value, true);
			if ($filteredValue === null)
			{
				continue;
			}

			$filtered[$key] = $filteredValue;
		}

		return $filtered;
	}

	private function filterScalarValue(mixed $rawValue, bool $allowOmit): mixed
	{
		if (!is_scalar($rawValue))
		{
			return $rawValue;
		}

		$value = trim((string)$rawValue);
		if ($value === '')
		{
			return $rawValue;
		}

		$item = $this->resolveItem($value);
		if ($item === null)
		{
			$this->setFirstError(self::ERROR_INCORRECT_VALUE);

			return $this->getRejectedValue($allowOmit);
		}

		$itemKey = $this->getItemKey($item['entityTypeId'], $item['entityId']);
		if (
			$this->itemPermissions !== null
			&& !isset($this->currentItemsMap[$itemKey])
			&& !$this->itemPermissions->canRead($item['entityTypeId'], $item['entityId'])
		)
		{
			$this->setFirstError(self::ERROR_ITEM_NOT_AVAILABLE);

			return $this->getRejectedValue($allowOmit);
		}

		return $rawValue;
	}

	private function getRejectedValue(bool $allowOmit): ?string
	{
		return $allowOmit ? null : '';
	}

	private function setFirstError(string $message): void
	{
		if ($this->errorMessage !== null)
		{
			return;
		}

		$this->errorMessage = $message;
	}

	private function resolveItem(string $value): ?array
	{
		$entityTypeName = '';
		$entityId = 0;

		if (str_contains($value, '_'))
		{
			$parts = explode('_', $value);
			if (count($parts) === 2)
			{
				[$type, $rawEntityId] = $parts;
				if ($type !== '' && $this->isIntegerString($rawEntityId))
				{
					$entityTypeName = \CCrmOwnerTypeAbbr::ResolveName($type);
					$entityId = (int)$rawEntityId;
				}
			}
		}
		elseif ($this->isIntegerString($value))
		{
			$entityId = (int)$value;
			$entityTypeName = $this->resolveDefaultEntityTypeName();
		}

		if ($entityId <= 0 || $entityTypeName === '')
		{
			return null;
		}

		$entityTypeId = \CCrmOwnerType::ResolveID($entityTypeName);
		if ($entityTypeId <= 0 || !isset($this->supportedTypes[$entityTypeId]))
		{
			return null;
		}

		return [
			'entityTypeId' => $entityTypeId,
			'entityId' => $entityId,
		];
	}

	private function resolveDefaultEntityTypeName(): string
	{
		if (count($this->supportedTypes) === 1)
		{
			return (string)reset($this->supportedTypes);
		}

		if (count($this->readableTypes) === 1)
		{
			return (string)reset($this->readableTypes);
		}

		return (string)reset($this->supportedTypes);
	}

	private function getItemKey(int $entityTypeId, int $entityId): string
	{
		return $entityTypeId . ':' . $entityId;
	}

	private function isIntegerString(string $value): bool
	{
		if ($value === '' || !is_numeric($value))
		{
			return false;
		}

		$numericValue = $value + 0;

		return ((string)(int)$numericValue === (string)$numericValue);
	}
}
