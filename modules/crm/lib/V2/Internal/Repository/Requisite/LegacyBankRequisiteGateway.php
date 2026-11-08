<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\BankDetailTable;
use Bitrix\Crm\EntityBankDetail;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteError;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteField;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteTemplate;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;

/** @internal */
final class LegacyBankRequisiteGateway
{
	private const US_COUNTRY_ID = 122;

	private const LEGACY_FIELD_BY_PUBLIC_FIELD = [
		'bankName' => 'RQ_BANK_NAME',
		'bankAddress' => 'RQ_BANK_ADDR',
		'bankCode' => 'RQ_BANK_CODE',
		'bankAgencyName' => 'RQ_AGENCY_NAME',
		'bankRoutingNumber' => 'RQ_BANK_ROUTE_NUM',
		'bik' => 'RQ_BIK',
		'mfo' => 'RQ_MFO',
		'bankAccountHolderName' => 'RQ_ACC_NAME',
		'bankAccountNumber' => 'RQ_ACC_NUM',
		'iik' => 'RQ_IIK',
		'bankAccountCurrency' => 'RQ_ACC_CURRENCY',
		'correspondentAccountNumber' => 'RQ_COR_ACC_NUM',
		'bankAccountType' => 'RQ_ACC_TYPE',
		'iban' => 'RQ_IBAN',
		'swift' => 'RQ_SWIFT',
		'bic' => 'RQ_BIC',
		'codeb' => 'RQ_CODEB',
		'codeg' => 'RQ_CODEG',
		'rib' => 'RQ_RIB',
	];

	/** @var array<int, BankRequisiteTemplate> */
	private array $templates = [];

	/**
	 * Finds the parent requisite bound to a bank detail.
	 *
	 * @return array{requisiteId: int}|null
	 */
	public function findBankBinding(int $id): ?array
	{
		$row = BankDetailTable::query()
			->setSelect(['ENTITY_TYPE_ID', 'ENTITY_ID'])
			->where('ID', $id)
			->setLimit(1)
			->fetch()
		;
		if (!$row || (int)$row['ENTITY_TYPE_ID'] !== \CCrmOwnerType::Requisite)
		{
			return null;
		}

		return ['requisiteId' => (int)$row['ENTITY_ID']];
	}

	/** Finds a requisite and its owner context. */
	public function findRequisite(int $id): ?RequisiteContext
	{
		$row = EntityRequisite::getSingleInstance()->getList([
			'select' => ['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID', 'PRESET_ID', 'COUNTRY_ID' => 'PRESET.COUNTRY_ID'],
			'filter' => ['=ID' => $id],
			'limit' => 1,
		])->fetch();

		return $row ? $this->contextFromRow($row) : null;
	}

	/** Checks whether the requisite owner exists. */
	public function ownerExists(int $ownerTypeId, int $ownerId): bool
	{
		return EntityRequisite::getSingleInstance()->validateEntityExists($ownerTypeId, $ownerId);
	}

	/** Builds the bank-field availability template for a requisite country. */
	public function buildTemplate(RequisiteContext $context): BankRequisiteTemplate
	{
		$countryId = $context->countryId > 0 ? $context->countryId : self::US_COUNTRY_ID;
		if (isset($this->templates[$countryId]))
		{
			return $this->templates[$countryId];
		}

		$legacyFields = EntityBankDetail::getSingleInstance()->getFormFieldsInfoByCountry($countryId);
		$availability = [];
		foreach (BankRequisiteField::cases() as $field)
		{
			$availability[$field->value] = isset($legacyFields[self::LEGACY_FIELD_BY_PUBLIC_FIELD[$field->value]]);
		}

		$template = new BankRequisiteTemplate($availability);
		$this->templates[$countryId] = $template;

		return $template;
	}

	/** Finds a bank requisite and maps it to the V2 entity. */
	public function findBank(int $id, BankRequisiteTemplate $template): ?BankRequisite
	{
		$row = EntityBankDetail::getSingleInstance()->getById($id);

		return $row ? $this->entityFromRow($row, $template) : null;
	}

	/**
	 * Loads an ID-ordered page of bank requisites for one requisite.
	 *
	 * @return BankRequisite[]
	 */
	public function findBanksPageByRequisite(RequisiteContext $context, int $afterId, int $limit): array
	{
		$rows = BankDetailTable::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE_ID', \CCrmOwnerType::Requisite)
			->where('ENTITY_ID', $context->requisiteId)
			->where('ID', '>', $afterId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(max(1, $limit))
			->exec()
		;

		return $this->entitiesFromRows($rows, [$context->requisiteId => $context]);
	}

	/**
	 * Loads an ID-ordered page of bank requisites for an owner across its requisites.
	 *
	 * @return BankRequisite[]
	 */
	public function findBanksPageByOwner(
		int $ownerTypeId,
		int $ownerId,
		int $afterId,
		int $limit,
	): array
	{
		$rows = BankDetailTable::query()
			->registerRuntimeField('REQUISITE', new Reference(
				'REQUISITE',
				RequisiteTable::class,
				Join::on('this.ENTITY_ID', 'ref.ID')
					->where('this.ENTITY_TYPE_ID', \CCrmOwnerType::Requisite),
			))
			->setSelect([
				'*',
				'REQUISITE_OWNER_TYPE_ID' => 'REQUISITE.ENTITY_TYPE_ID',
				'REQUISITE_OWNER_ID' => 'REQUISITE.ENTITY_ID',
				'REQUISITE_PRESET_ID' => 'REQUISITE.PRESET_ID',
				'REQUISITE_COUNTRY_ID' => 'REQUISITE.PRESET.COUNTRY_ID',
			])
			->where('REQUISITE.ENTITY_TYPE_ID', $ownerTypeId)
			->where('REQUISITE.ENTITY_ID', $ownerId)
			->where('ID', '>', $afterId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(max(1, $limit))
			->exec()
		;

		$entities = [];
		while ($row = $rows->fetch())
		{
			$context = new RequisiteContext(
				(int)$row['ENTITY_ID'],
				(int)$row['REQUISITE_OWNER_TYPE_ID'],
				(int)$row['REQUISITE_OWNER_ID'],
				(int)$row['REQUISITE_PRESET_ID'],
				(int)$row['REQUISITE_COUNTRY_ID'],
			);
			$entities[] = $this->entityFromRow($row, $this->buildTemplate($context));
		}

		return $entities;
	}

	/** Adds a bank requisite through the legacy entity API. */
	public function add(
		BankRequisite $entity,
		BankRequisiteTemplate $template,
		int $countryId,
		int $presetId,
	): Result
	{
		$fields = $this->legacyFields($entity, $template, null);
		$fields['ENTITY_TYPE_ID'] = \CCrmOwnerType::Requisite;
		$fields['ENTITY_ID'] = $entity->getRequisiteId();
		$fields['COUNTRY_ID'] = $countryId > 0 ? $countryId : self::US_COUNTRY_ID;

		$legacyResult = EntityBankDetail::getSingleInstance()->add($fields, $this->writeOptions($presetId));
		$result = $this->normalizeLegacyResult($legacyResult, $countryId);
		if ($result->isSuccess())
		{
			$result->setData(['id' => (int)$legacyResult->getId()]);
		}

		return $result;
	}

	/**
	 * Updates the selected bank requisite fields through the legacy entity API.
	 *
	 * @param string[] $modifiedFields
	 */
	public function update(
		int $id,
		BankRequisite $entity,
		BankRequisiteTemplate $template,
		array $modifiedFields,
		int $presetId,
		int $countryId,
	): Result
	{
		return $this->normalizeLegacyResult(
			EntityBankDetail::getSingleInstance()->update(
				$id,
				$this->legacyFields($entity, $template, $modifiedFields),
				$this->writeOptions($presetId),
			),
			$countryId,
		);
	}

	/** Deletes a bank requisite through the legacy entity API. */
	public function delete(int $id): Result
	{
		return $this->normalizeLegacyResult(EntityBankDetail::getSingleInstance()->delete($id));
	}

	/**
	 * @param array<int, RequisiteContext> $contexts
	 * @return BankRequisite[]
	 */
	private function entitiesFromRows(object $rows, array $contexts): array
	{
		$entities = [];
		while ($row = $rows->fetch())
		{
			$context = $contexts[(int)$row['ENTITY_ID']] ?? null;
			if ($context === null)
			{
				continue;
			}
			$entities[] = $this->entityFromRow($row, $this->buildTemplate($context));
		}

		return $entities;
	}

	/** @param array<string, mixed> $row */
	private function contextFromRow(array $row): RequisiteContext
	{
		return new RequisiteContext(
			(int)$row['ID'],
			(int)$row['ENTITY_TYPE_ID'],
			(int)$row['ENTITY_ID'],
			(int)($row['PRESET_ID'] ?? 0),
			(int)($row['COUNTRY_ID'] ?? 0),
		);
	}

	/** @return array<string, mixed> */
	private function writeOptions(int $presetId): array
	{
		return $presetId > 0
			? ['FIELD_CHECK_OPTIONS' => ['PRESET_ID' => $presetId]]
			: ['DISABLE_REQUIRED_USER_FIELD_CHECK' => true]
		;
	}

	/** @param array<string, mixed> $row */
	private function entityFromRow(array $row, BankRequisiteTemplate $template): BankRequisite
	{
		$values = [];
		foreach (self::LEGACY_FIELD_BY_PUBLIC_FIELD as $publicField => $legacyField)
		{
			$field = BankRequisiteField::from($publicField);
			if (!$template->has($field))
			{
				$values[$publicField] = null;
				continue;
			}

			$value = $row[$legacyField] ?? null;
			$values[$publicField] = $value === null || $value === '' ? null : (string)$value;
		}

		return new BankRequisite(...[
			'id' => (int)$row['ID'],
			'requisiteId' => (int)$row['ENTITY_ID'],
			'name' => (string)$row['NAME'],
			'comments' => ($row['COMMENTS'] ?? '') === '' ? null : (string)$row['COMMENTS'],
			...$values,
			'template' => $template,
		]);
	}

	/**
	 * @param string[]|null $modifiedFields
	 * @return array<string, mixed>
	 */
	private function legacyFields(
		BankRequisite $entity,
		BankRequisiteTemplate $template,
		?array $modifiedFields,
	): array
	{
		$fields = [];
		if ($modifiedFields === null || in_array('name', $modifiedFields, true))
		{
			$fields['NAME'] = $entity->getName();
		}
		if ($modifiedFields === null || in_array('comments', $modifiedFields, true))
		{
			$fields['COMMENTS'] = $entity->getComments() ?? '';
		}

		foreach (BankRequisiteField::cases() as $field)
		{
			if (!$template->has($field))
			{
				continue;
			}
			if ($modifiedFields !== null && !in_array($field->value, $modifiedFields, true))
			{
				continue;
			}

			$fields[self::LEGACY_FIELD_BY_PUBLIC_FIELD[$field->value]] = $entity->getField($field) ?? '';
		}

		return $fields;
	}

	private function normalizeLegacyResult(Result $legacyResult, int $countryId = self::US_COUNTRY_ID): Result
	{
		if ($legacyResult->isSuccess())
		{
			return $legacyResult;
		}

		$result = new Result();
		$fieldTitles = EntityBankDetail::getSingleInstance()->getFieldsTitles(
			$countryId > 0 ? $countryId : self::US_COUNTRY_ID,
		);
		$fieldMap = [
			'name' => 'NAME',
			'comments' => 'COMMENTS',
			...self::LEGACY_FIELD_BY_PUBLIC_FIELD,
		];
		foreach ($legacyResult->getErrors() as $error)
		{
			$message = $error->getMessage();
			$customData = (array)$error->getCustomData();
			foreach ($fieldMap as $publicField => $legacyField)
			{
				$legacyTitle = (string)($fieldTitles[$legacyField] ?? '');
				$matchedField = str_contains($message, $legacyField)
					? $legacyField
					: ($legacyTitle !== '' && str_contains($message, $legacyTitle) ? $legacyTitle : null)
				;
				if ($matchedField === null)
				{
					continue;
				}

				$message = str_replace($matchedField, $publicField, $message);
				$customData['field'] = $publicField;
				break;
			}

			$result->addError(new Error($message, BankRequisiteError::VALIDATION, $customData));
		}

		return $result;
	}
}
