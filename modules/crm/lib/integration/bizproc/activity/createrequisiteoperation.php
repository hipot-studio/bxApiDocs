<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Activity;

use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\EntityBankDetail;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\Integrity\DuplicateBankDetailCriterion;
use Bitrix\Crm\Integrity\DuplicateRequisiteCriterion;
use Bitrix\Crm\Integrity\DuplicateVolatileCriterion;
use Bitrix\Crm\Integrity\Volatile\FieldCategory;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Throwable;

final class CreateRequisiteOperation
{
	public const RETURN_REQUISITE_ID = 'RequisiteId';
	public const RETURN_STATUS = 'Status';
	public const RETURN_ERROR_MESSAGE = 'ErrorMessage';
	public const RETURN_WARNINGS = 'Warnings';

	public const STATUS_CREATED = 'created';
	public const STATUS_FOUND = 'found';
	public const STATUS_ERROR = 'error';

	private object $lock;
	private object $requisite;
	private object $bankDetail;
	private object $connection;
	private $messageResolver;
	private $rollbackRestorer;

	public function __construct(
		?object $lock = null,
		?object $requisite = null,
		?object $bankDetail = null,
		?object $connection = null,
		?callable $messageResolver = null,
		?callable $rollbackRestorer = null,
	)
	{
		$this->lock = $lock ?? new CreateRequisiteLock();
		$this->requisite = $requisite ?? EntityRequisite::getSingleInstance();
		$this->bankDetail = $bankDetail ?? EntityBankDetail::getSingleInstance();
		$this->connection = $connection ?? Application::getConnection();
		$this->messageResolver = $messageResolver;
		$this->rollbackRestorer = $rollbackRestorer;
	}

	public function execute(int $ownerTypeId, int $ownerId, int $presetId, callable $creationDataFactory): Result
	{
		try
		{
			if (!$this->lock->acquire($ownerTypeId, $ownerId, $presetId))
			{
				return $this->createErrorResult(
					'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_LOCK',
					'Unable to acquire requisite creation lock.',
				);
			}

			$existing = $this->findExistingRequisite($ownerTypeId, $ownerId, $presetId);
			if ($existing !== null)
			{
				return $this->createSuccessResult((int)$existing['ID'], self::STATUS_FOUND);
			}

			$creationData = $creationDataFactory();
			$shouldSetDefault = !$this->hasAnyRequisite($ownerTypeId, $ownerId);

			return $this->createRequisite($ownerTypeId, $ownerId, $presetId, $creationData, $shouldSetDefault);
		}
		catch (Throwable)
		{
			return $this->createExceptionResult();
		}
		finally
		{
			$this->lock->release();
		}
	}

	private function createRequisite(
		int $ownerTypeId,
		int $ownerId,
		int $presetId,
		array $creationData,
		bool $shouldSetDefault,
	): Result
	{
		$preparedDataResult = $this->prepareCreationData($ownerTypeId, $ownerId, $presetId, $creationData);
		if (!$preparedDataResult->isSuccess())
		{
			return $preparedDataResult;
		}

		$preparedData = $preparedDataResult->getData();
		$requisiteFields = $preparedData['requisiteFields'];
		$bankDetailFields = $preparedData['bankDetailFields'];
		$warnings = $preparedData['warnings'];

		$options = [
			'ALLOW_SET_SYSTEM_FIELDS' => true,
			'FIELD_CHECK_OPTIONS' => [
				'PRESET_ID' => $presetId,
			],
		];

		$requisiteId = 0;
		$bankDetailId = 0;
		$transactionStarted = false;

		try
		{
			$this->connection->startTransaction();
			$transactionStarted = true;

			$addRequisiteResult = $this->requisite->add($requisiteFields, $options);
			if (!$this->isResultSuccess($addRequisiteResult))
			{
				$this->rollback($ownerTypeId, $ownerId, $requisiteId);

				return $this->createErrorResultFromResult($addRequisiteResult);
			}

			$requisiteId = $this->extractResultId($addRequisiteResult);
			if ($requisiteId <= 0)
			{
				$this->rollback($ownerTypeId, $ownerId, $requisiteId);

				return $this->createErrorResult(
					'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_REQUISITE_ID',
					'Requisite was created without an identifier.',
				);
			}

			if ($bankDetailFields !== [])
			{
				$bankDetailFields['ENTITY_TYPE_ID'] = \CCrmOwnerType::Requisite;
				$bankDetailFields['ENTITY_ID'] = $requisiteId;

				$addBankDetailResult = $this->bankDetail->add($bankDetailFields);
				if (!$this->isResultSuccess($addBankDetailResult))
				{
					$this->rollback($ownerTypeId, $ownerId, $requisiteId);

					return $this->createErrorResultFromResult($addBankDetailResult);
				}

				$bankDetailId = $this->extractResultId($addBankDetailResult);
			}

			if ($shouldSetDefault && !EntityRequisite::setDef($ownerTypeId, $ownerId, $requisiteId, $bankDetailId, true))
			{
				$this->rollback($ownerTypeId, $ownerId, $requisiteId);

				return $this->createErrorResult(
					'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_SET_DEFAULT',
					'Unable to set the created requisite as default.',
				);
			}

			$this->connection->commitTransaction();
			$transactionStarted = false;
		}
		catch (Throwable)
		{
			if ($transactionStarted)
			{
				$this->rollback($ownerTypeId, $ownerId, $requisiteId);
			}

			return $this->createExceptionResult();
		}

		$result = $this->createSuccessResult($requisiteId, self::STATUS_CREATED);
		if ($warnings !== [])
		{
			$data = $result->getData();
			$data[self::RETURN_WARNINGS] = $warnings;
			$result->setData($data);
		}

		return $result;
	}

	private function prepareCreationData(int $ownerTypeId, int $ownerId, int $presetId, array $creationData): Result
	{
		$name = $this->normalizeName($creationData['name'] ?? '');
		if ($name === '')
		{
			return $this->createErrorResult(
				'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_NAME_EMPTY',
				'Requisite name is required.',
			);
		}

		$authorId = (int)($creationData['authorId'] ?? 0);
		$countryId = (int)($creationData['countryId'] ?? 0);
		if ($countryId <= 0)
		{
			$countryId = (int)$this->requisite->getCountryIdByPresetId($presetId);
		}

		$requisiteFields = is_array($creationData['requisiteFields'] ?? null)
			? $creationData['requisiteFields']
			: [];
		$requisiteFields['ENTITY_TYPE_ID'] = $ownerTypeId;
		$requisiteFields['ENTITY_ID'] = $ownerId;
		$requisiteFields['PRESET_ID'] = $presetId;
		$requisiteFields['NAME'] = $name;
		$requisiteFields['CREATED_BY_ID'] = $authorId;
		$requisiteFields['DATE_CREATE'] = new DateTime();

		unset($requisiteFields['ID'], $requisiteFields['DATE_MODIFY'], $requisiteFields['MODIFY_BY_ID']);

		$addressFields = is_array($creationData['addressFields'] ?? null)
			? $creationData['addressFields']
			: [];
		$addressTypeId = (int)($creationData['addressTypeId'] ?? 0);
		unset($requisiteFields[EntityRequisite::ADDRESS]);

		if ($this->isAddressFilled($addressFields))
		{
			if (!EntityAddressType::isDefined($addressTypeId))
			{
				return $this->createErrorResult(
					'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_ADDRESS_TYPE',
					'Address type is not selected.',
				);
			}

			$requisiteFields[EntityRequisite::ADDRESS] = [
				$addressTypeId => $addressFields,
			];
		}

		$options = [
			'ALLOW_SET_SYSTEM_FIELDS' => true,
			'FIELD_CHECK_OPTIONS' => [
				'PRESET_ID' => $presetId,
			],
		];

		$checkRequisiteResult = $this->requisite->checkBeforeAdd($requisiteFields, $options);
		if (!$this->isResultSuccess($checkRequisiteResult))
		{
			return $this->createErrorResultFromResult($checkRequisiteResult);
		}

		$bankDetailFields = is_array($creationData['bankDetailFields'] ?? null)
			? $creationData['bankDetailFields']
			: [];
		$bankDetailFields = $this->clearEmptySystemFields($bankDetailFields);

		if (!$this->isBankDetailFilled($bankDetailFields))
		{
			$bankDetailFields = [];
		}
		else
		{
			$bankDetailFields['NAME'] = $this->normalizeName($bankDetailFields['NAME'] ?? '');
			if ($bankDetailFields['NAME'] === '')
			{
				return $this->createErrorResult(
					'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_BANK_DETAIL_NAME_EMPTY',
					'Bank detail name is required when bank detail fields are filled.',
				);
			}

			$bankDetailFields['ENTITY_TYPE_ID'] = \CCrmOwnerType::Requisite;
			$bankDetailFields['ENTITY_ID'] = 1;
			$bankDetailFields['COUNTRY_ID'] = $countryId;
			unset($bankDetailFields['ID'], $bankDetailFields['DATE_CREATE'], $bankDetailFields['DATE_MODIFY'], $bankDetailFields['MODIFY_BY_ID']);

			$checkBankDetailResult = $this->bankDetail->checkBeforeAdd(
				$bankDetailFields,
				[
					'FIELD_CHECK_OPTIONS' => [
						'PRESET_ID' => $presetId,
					],
				],
			);
			if (!$this->isResultSuccess($checkBankDetailResult))
			{
				return $this->createErrorResultFromResult($checkBankDetailResult);
			}
		}

		$result = new Result();
		$result->setData([
			'requisiteFields' => $requisiteFields,
			'bankDetailFields' => $bankDetailFields,
			'warnings' => array_values(array_filter((array)($creationData['warnings'] ?? []))),
		]);

		return $result;
	}

	private function findExistingRequisite(int $ownerTypeId, int $ownerId, int $presetId): ?array
	{
		$dbResult = $this->requisite->getList([
			'select' => ['ID'],
			'filter' => [
				'=ENTITY_TYPE_ID' => $ownerTypeId,
				'=ENTITY_ID' => $ownerId,
				'=PRESET_ID' => $presetId,
			],
			'order' => [
				'SORT' => 'ASC',
				'ID' => 'ASC',
			],
			'limit' => 1,
		]);

		return $this->fetchFirstRow($dbResult);
	}

	private function hasAnyRequisite(int $ownerTypeId, int $ownerId): bool
	{
		$dbResult = $this->requisite->getList([
			'select' => ['ID'],
			'filter' => [
				'=ENTITY_TYPE_ID' => $ownerTypeId,
				'=ENTITY_ID' => $ownerId,
			],
			'order' => [
				'SORT' => 'ASC',
				'ID' => 'ASC',
			],
			'limit' => 1,
		]);

		return $this->fetchFirstRow($dbResult) !== null;
	}

	private function fetchFirstRow(mixed $dbResult): ?array
	{
		if (is_array($dbResult))
		{
			$row = reset($dbResult);
			if ($row === false)
			{
				return null;
			}

			return is_array($row) ? $row : null;
		}

		if (is_object($dbResult) && method_exists($dbResult, 'fetch'))
		{
			$row = $dbResult->fetch();

			return is_array($row) ? $row : null;
		}

		return null;
	}

	private function normalizeName(mixed $value): string
	{
		$name = trim($this->stringify($value));

		return mb_substr($name, 0, 255);
	}

	private function stringify(mixed $value): string
	{
		if (is_array($value))
		{
			$value = \CBPHelper::MakeArrayFlat($value);

			return implode(', ', array_map([$this, 'stringify'], $value));
		}

		if (is_object($value) && !($value instanceof \Stringable))
		{
			return '';
		}

		return (string)$value;
	}

	private function isAddressFilled(array $addressFields): bool
	{
		return
			(EntityAddress::isSet($addressFields) && !EntityAddress::isEmpty($addressFields))
			|| (int)($addressFields['LOC_ADDR_ID'] ?? 0) > 0
			|| isset($addressFields['LOC_ADDR']);
	}

	private function isBankDetailFilled(array $bankDetailFields): bool
	{
		foreach ($bankDetailFields as $fieldName => $value)
		{
			if (in_array($fieldName, ['ENTITY_TYPE_ID', 'ENTITY_ID', 'COUNTRY_ID'], true))
			{
				continue;
			}

			if (!\CBPHelper::isEmptyValue($value))
			{
				return true;
			}
		}

		return false;
	}

	private function clearEmptySystemFields(array $fields): array
	{
		foreach (['ENTITY_TYPE_ID', 'ENTITY_ID', 'COUNTRY_ID'] as $fieldName)
		{
			if (array_key_exists($fieldName, $fields) && \CBPHelper::isEmptyValue($fields[$fieldName]))
			{
				unset($fields[$fieldName]);
			}
		}

		return $fields;
	}

	private function rollback(int $ownerTypeId, int $ownerId, int $requisiteId): void
	{
		$this->connection->rollbackTransaction();
		$this->restoreSideEffectsAfterRollback($ownerTypeId, $ownerId, $requisiteId);
	}

	private function restoreSideEffectsAfterRollback(int $ownerTypeId, int $ownerId, int $requisiteId): void
	{
		if ($this->rollbackRestorer !== null)
		{
			($this->rollbackRestorer)($ownerTypeId, $ownerId, $requisiteId);

			return;
		}

		if ($this->isRequisiteOwner($ownerTypeId, $ownerId))
		{
			DuplicateRequisiteCriterion::registerByEntity($ownerTypeId, $ownerId);
			DuplicateBankDetailCriterion::registerByEntity($ownerTypeId, $ownerId);
			DuplicateVolatileCriterion::register($ownerTypeId, $ownerId, [FieldCategory::REQUISITE, FieldCategory::BANK_DETAIL]);
			\CCrmEntitySelectorHelper::clearPrepareRequisiteDataCacheByEntity($ownerTypeId, $ownerId);
		}

		if ($requisiteId > 0)
		{
			DuplicateRequisiteCriterion::registerByRequisite($requisiteId);
			DuplicateBankDetailCriterion::registerByParent(\CCrmOwnerType::Requisite, $requisiteId);
			DuplicateVolatileCriterion::register(\CCrmOwnerType::Requisite, $requisiteId, [FieldCategory::REQUISITE, FieldCategory::BANK_DETAIL]);
			\CCrmEntitySelectorHelper::clearPrepareRequisiteDataCacheByEntity(\CCrmOwnerType::Requisite, $requisiteId);
		}
	}

	private function isRequisiteOwner(int $ownerTypeId, int $ownerId): bool
	{
		return
			($ownerTypeId === \CCrmOwnerType::Contact || $ownerTypeId === \CCrmOwnerType::Company)
			&& $ownerId > 0;
	}

	private function createSuccessResult(int $requisiteId, string $status): Result
	{
		$result = new Result();
		$result->setData([
			self::RETURN_REQUISITE_ID => $requisiteId,
			self::RETURN_STATUS => $status,
			self::RETURN_ERROR_MESSAGE => '',
		]);

		return $result;
	}

	private function createErrorResultFromResult(mixed $sourceResult): Result
	{
		$message = $this->extractResultErrorMessage($sourceResult);
		if ($message === '')
		{
			$message = $this->getMessage(
				'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_UNKNOWN',
				[],
				'Unable to create requisite.',
			);
		}

		return $this->createErrorResultFromMessage($message);
	}

	private function createExceptionResult(): Result
	{
		return $this->createErrorResult(
			'CRM_CREATE_REQUISITE_ACTIVITY_ERROR_UNEXPECTED',
			'Unable to create requisite. Try again.',
		);
	}

	private function createErrorResult(string $messageCode, string $fallback): Result
	{
		return $this->createErrorResultFromMessage($this->getMessage($messageCode, [], $fallback));
	}

	private function createErrorResultFromMessage(string $message): Result
	{
		$result = new Result();
		$result->addError(new Error($message));
		$result->setData([
			self::RETURN_REQUISITE_ID => 0,
			self::RETURN_STATUS => self::STATUS_ERROR,
			self::RETURN_ERROR_MESSAGE => $message,
		]);

		return $result;
	}

	private function getMessage(string $code, array $replace, string $fallback): string
	{
		if ($this->messageResolver !== null)
		{
			$message = ($this->messageResolver)($code, $replace, $fallback);
			if (is_string($message) && $message !== '')
			{
				return $message;
			}
		}

		$message = Loc::getMessage($code, $replace);
		if (is_string($message) && $message !== '')
		{
			return $message;
		}

		return str_replace(array_keys($replace), array_values($replace), $fallback);
	}

	private function isResultSuccess(mixed $result): bool
	{
		if ($result instanceof Result)
		{
			return $result->isSuccess();
		}

		if (is_object($result) && method_exists($result, 'isSuccess'))
		{
			return (bool)$result->isSuccess();
		}

		return (bool)$result;
	}

	private function extractResultId(mixed $result): int
	{
		if (is_object($result) && method_exists($result, 'getId'))
		{
			return (int)$result->getId();
		}

		if (is_array($result) && isset($result['ID']))
		{
			return (int)$result['ID'];
		}

		if (is_numeric($result))
		{
			return (int)$result;
		}

		return 0;
	}

	private function extractResultErrorMessage(mixed $result): string
	{
		if ($result instanceof Result)
		{
			$messages = $result->getErrorMessages();

			return (string)end($messages);
		}

		if (is_object($result) && method_exists($result, 'getErrorMessages'))
		{
			$messages = $result->getErrorMessages();

			return is_array($messages) ? (string)end($messages) : '';
		}

		return '';
	}
}
