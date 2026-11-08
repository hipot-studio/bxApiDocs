<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\BankDetailTable;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Repository\Requisite\LegacyBankRequisiteGateway;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteContext;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteError;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteField;
use Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteTemplate;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Requisite\BankRequisiteProvider;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteFilter;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteListContext;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteSort;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;

/** @internal */
final class BankRequisiteService
{
	private const READ_BATCH_SIZE = 100;

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

	private readonly LegacyBankRequisiteGateway $gateway;

	public function __construct(
		private readonly int $userId,
		?LegacyBankRequisiteGateway $gateway = null,
	)
	{
		$this->gateway = $gateway ?? new LegacyBankRequisiteGateway();
	}

	/**
	 * Reads all bank requisites for the supplied company owners in one batch.
	 *
	 * @param int[] $companyIds
	 * @param string[]|null $selectedFields
	 */
	public function getListByCompanyIds(array $companyIds, ?array $selectedFields = null): Result
	{
		$companyIds = array_values(array_unique(array_filter(
			array_map(static fn(mixed $id): int => (int)$id, $companyIds),
			static fn(int $id): bool => $id > 0,
		)));
		$result = new Result();
		if ($companyIds === [])
		{
			return $result->setData([BankRequisiteProvider::DATA_BANK_REQUISITES => []]);
		}

		$permissions = Container::getInstance()->getUserPermissions($this->userId)->myCompany();
		foreach ($companyIds as $companyId)
		{
			if (!$permissions->canReadBaseFields($companyId))
			{
				return $result->addError(
					new Error(
						'The company cannot be read.',
						'CRM_MY_COMPANY_ACCESS_DENIED',
						['id' => $companyId],
					),
				);
			}
		}

		$grouped = array_fill_keys($companyIds, []);
		try
		{
			$select = ['ID', 'ENTITY_ID', 'NAME', 'COMMENTS'];
			if ($selectedFields !== null)
			{
				$select = array_merge($select, array_intersect($selectedFields, array_keys(self::LEGACY_FIELD_BY_PUBLIC_FIELD)));
				$select = array_map(
					static fn(string $field): string => self::LEGACY_FIELD_BY_PUBLIC_FIELD[$field] ?? $field,
					$select,
				);
			}
			else
			{
				$select[] = '*';
			}

			$rows = BankDetailTable::query()
				->registerRuntimeField('REQUISITE', new Reference(
					'REQUISITE',
					RequisiteTable::class,
					Join::on('this.ENTITY_ID', 'ref.ID')
						->where('this.ENTITY_TYPE_ID', \CCrmOwnerType::Requisite),
				))
				->setSelect([
					...array_values(array_unique($select)),
					'REQUISITE_OWNER_TYPE_ID' => 'REQUISITE.ENTITY_TYPE_ID',
					'REQUISITE_OWNER_ID' => 'REQUISITE.ENTITY_ID',
					'REQUISITE_PRESET_ID' => 'REQUISITE.PRESET_ID',
					'REQUISITE_COUNTRY_ID' => 'REQUISITE.PRESET.COUNTRY_ID',
				])
				->where('REQUISITE.ENTITY_TYPE_ID', OwnerType::COMPANY)
				->whereIn('REQUISITE.ENTITY_ID', $companyIds)
				->setOrder(['ID' => 'ASC'])
				->exec()
			;

			while ($row = $rows->fetch())
			{
				$companyId = (int)($row['REQUISITE_OWNER_ID'] ?? 0);
				if (!isset($grouped[$companyId]))
				{
					continue;
				}

				$context = new RequisiteContext(
					(int)$row['ENTITY_ID'],
					(int)$row['REQUISITE_OWNER_TYPE_ID'],
					$companyId,
					(int)$row['REQUISITE_PRESET_ID'],
					(int)$row['REQUISITE_COUNTRY_ID'],
				);
				$grouped[$companyId][] = $this->createBankRequisite(
					$row,
					$this->gateway->buildTemplate($context),
				);
			}
		}
		catch (\Throwable $exception)
		{
			return $result->addError(
				new Error($exception->getMessage(), 'CRM_MY_COMPANY_BANK_REQUISITE_READ_ERROR'),
			);
		}

		return $result->setData([BankRequisiteProvider::DATA_BANK_REQUISITES => $grouped]);
	}

	public function getById(EntityType $expectedOwnerType, int $id): Result
	{
		$context = $this->resolveByBankId($expectedOwnerType, $id, 'read');
		if ($context === null)
		{
			return self::notFound($id);
		}

		$template = $this->gateway->buildTemplate($context);
		$entity = $this->gateway->findBank($id, $template);
		if ($entity === null)
		{
			return self::notFound($id);
		}

		return (new Result())->setData([BankRequisiteProvider::DATA_BANK_REQUISITE => $entity]);
	}

	/** @param string[]|null $modifiedFields */
	public function add(EntityType $expectedOwnerType, BankRequisite $entity, ?array $modifiedFields = null): Result
	{
		$requisiteId = (int)$entity->getRequisiteId();
		$context = $this->resolveRequisite($expectedOwnerType, $requisiteId, 'create');
		if ($context === null)
		{
			return self::notFound($requisiteId);
		}

		$template = $this->gateway->buildTemplate($context);
		$availability = $this->validateAvailableFields($entity, $template, $modifiedFields);
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		$writeResult = $this->gateway->add(
			$entity->withTemplate($template),
			$template,
			$context->countryId,
			$context->presetId,
		);
		if (!$writeResult->isSuccess())
		{
			return $writeResult;
		}

		$id = (int)($writeResult->getData()['id'] ?? 0);
		$stored = $this->gateway->findBank($id, $template);

		return $stored === null
			? self::notFound($id)
			: (new Result())->setData([BankRequisiteProvider::DATA_BANK_REQUISITE => $stored])
		;
	}

	/** @param string[] $modifiedFields */
	public function update(
		EntityType $expectedOwnerType,
		BankRequisite $entity,
		array $modifiedFields,
	): Result
	{
		$id = (int)$entity->getId();
		$context = $this->resolveByBankId($expectedOwnerType, $id, 'update');
		if ($context === null)
		{
			return self::notFound($id);
		}

		$template = $this->gateway->buildTemplate($context);
		$availability = $this->validateAvailableFields($entity, $template, $modifiedFields);
		if (!$availability->isSuccess())
		{
			return $availability;
		}

		$writeResult = $this->gateway->update(
			$id,
			$entity->withTemplate($template),
			$template,
			$modifiedFields,
			$context->presetId,
			$context->countryId,
		);
		if (!$writeResult->isSuccess())
		{
			return $writeResult;
		}

		$stored = $this->gateway->findBank($id, $template);

		return $stored === null
			? self::notFound($id)
			: (new Result())->setData([BankRequisiteProvider::DATA_BANK_REQUISITE => $stored])
		;
	}

	public function delete(EntityType $expectedOwnerType, int $id): Result
	{
		$context = $this->resolveByBankId($expectedOwnerType, $id, 'delete');
		if ($context === null)
		{
			return self::notFound($id);
		}

		return $this->gateway->delete($id);
	}

	public function getList(
		BankRequisiteListContext $listContext,
		?BankRequisiteFilter $filter,
		?BankRequisiteSort $sort,
		?PagerInterface $pager,
	): Result
	{
		$itemType = $listContext->getItemType();
		if ($listContext->isForItem())
		{
			return $this->getListByItem($itemType, $listContext, $filter, $sort, $pager);
		}

		return $this->getListByRequisite($itemType, $listContext, $filter, $sort, $pager);
	}

	private function getListByItem(
		EntityType $itemType,
		BankRequisiteListContext $listContext,
		?BankRequisiteFilter $filter,
		?BankRequisiteSort $sort,
		?PagerInterface $pager,
	): Result
	{
		$itemId = $listContext->getTargetId();
		if (
			$itemId <= 0
			|| !$this->gateway->ownerExists($itemType->getId(), $itemId)
			|| !$this->canRead($itemType->getId(), $itemId)
		)
		{
			return self::notFound($itemId);
		}

		return $this->processList($itemType, $listContext, null, $filter, $sort, $pager);
	}

	private function getListByRequisite(
		EntityType $itemType,
		BankRequisiteListContext $listContext,
		?BankRequisiteFilter $filter,
		?BankRequisiteSort $sort,
		?PagerInterface $pager,
	): Result
	{
		$requisiteId = $listContext->getTargetId();
		$requisiteContext = $this->resolveRequisite($itemType, $requisiteId, 'read');
		if ($requisiteContext === null)
		{
			return self::notFound($requisiteId);
		}

		return $this->processList($itemType, $listContext, $requisiteContext, $filter, $sort, $pager);
	}

	private function processList(
		EntityType $itemType,
		BankRequisiteListContext $listContext,
		?RequisiteContext $requisiteContext,
		?BankRequisiteFilter $filter,
		?BankRequisiteSort $sort,
		?PagerInterface $pager,
	): Result
	{
		$processor = new BankRequisiteListProcessor($filter, $sort ?? new BankRequisiteSort(), $pager);
		$batchSize = $processor->usesNaturalOrder() && $filter === null
			? min(self::READ_BATCH_SIZE, max(1, $processor->getWindowSize()))
			: self::READ_BATCH_SIZE;

		return $processor->process(
			$this->iterateBanks($itemType, $listContext, $requisiteContext, $batchSize),
			$batchSize,
		);
	}

	private function resolveByBankId(
		EntityType $expectedOwnerType,
		int $id,
		string $operation,
	): ?RequisiteContext
	{
		$binding = $id > 0 ? $this->gateway->findBankBinding($id) : null;
		if ($binding === null)
		{
			return null;
		}

		return $this->resolveRequisite($expectedOwnerType, $binding['requisiteId'], $operation);
	}

	private function resolveRequisite(
		EntityType $expectedOwnerType,
		int $requisiteId,
		string $operation,
	): ?RequisiteContext
	{
		$context = $requisiteId > 0 ? $this->gateway->findRequisite($requisiteId) : null;
		if (
			$context === null
			|| $context->ownerTypeId !== $expectedOwnerType->getId()
			|| !$this->gateway->ownerExists($context->ownerTypeId, $context->ownerId)
			|| !$this->isAllowed($context, $operation)
		)
		{
			return null;
		}

		return $context;
	}

	/** @return \Generator<int, BankRequisite> */
	private function iterateBanks(
		EntityType $itemType,
		BankRequisiteListContext $listContext,
		?RequisiteContext $resolvedContext,
		int $batchSize,
	): \Generator
	{
		$afterId = 0;
		do
		{
			$entities = $listContext->isForItem()
				? $this->gateway->findBanksPageByOwner(
					$itemType->getId(),
					$listContext->getTargetId(),
					$afterId,
					$batchSize,
				)
				: $this->gateway->findBanksPageByRequisite(
					$resolvedContext,
					$afterId,
					$batchSize,
				)
			;
			foreach ($entities as $entity)
			{
				$afterId = (int)$entity->getId();
				yield $entity;
			}
		}
		while (count($entities) === $batchSize);
	}

	private function isAllowed(RequisiteContext $context, string $operation): bool
	{
		return match ($operation)
		{
			'read' => $this->canRead($context->ownerTypeId, $context->ownerId),
			'create' => $this->canCreate($context->ownerTypeId, $context->ownerId),
			'update' => $this->canUpdate($context->ownerTypeId, $context->ownerId),
			'delete' => $this->canDelete($context->ownerTypeId, $context->ownerId),
			default => false,
		};
	}

	private function canRead(int $ownerTypeId, int $ownerId): bool
	{
		$permissions = Container::getInstance()->getUserPermissions($this->userId);

		return $this->isMyCompany($ownerTypeId, $ownerId)
			? $permissions->myCompany()->canReadBaseFields($ownerId)
			: $permissions->item()->canRead($ownerTypeId, $ownerId)
		;
	}

	private function canCreate(int $ownerTypeId, int $ownerId): bool
	{
		$permissions = Container::getInstance()->getUserPermissions($this->userId);
		if (!$permissions->entityType()->canAddItemsInCategory($ownerTypeId, 0))
		{
			return false;
		}

		return $this->isMyCompany($ownerTypeId, $ownerId)
			? $permissions->myCompany()->canUpdate()
			: $permissions->item()->canUpdate($ownerTypeId, $ownerId)
		;
	}

	private function canUpdate(int $ownerTypeId, int $ownerId): bool
	{
		$permissions = Container::getInstance()->getUserPermissions($this->userId);

		return $this->isMyCompany($ownerTypeId, $ownerId)
			? $permissions->myCompany()->canUpdate()
			: $permissions->item()->canUpdate($ownerTypeId, $ownerId)
		;
	}

	private function canDelete(int $ownerTypeId, int $ownerId): bool
	{
		$permissions = Container::getInstance()->getUserPermissions($this->userId);

		return $this->isMyCompany($ownerTypeId, $ownerId)
			? $permissions->myCompany()->canDelete()
			: $permissions->item()->canDelete($ownerTypeId, $ownerId)
		;
	}

	/** @param array<string, mixed> $row */
	private function createBankRequisite(array $row, BankRequisiteTemplate $template): BankRequisite
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

	private function isMyCompany(int $ownerTypeId, int $ownerId): bool
	{
		return $ownerTypeId === \CCrmOwnerType::Company && \CCrmCompany::isMyCompany($ownerId);
	}

	/** @param string[]|null $modifiedFields */
	private function validateAvailableFields(
		BankRequisite $entity,
		\Bitrix\Crm\V2\Public\Entity\Requisite\BankRequisiteTemplate $template,
		?array $modifiedFields = null,
	): Result
	{
		foreach (BankRequisiteField::cases() as $field)
		{
			$isWritten = $modifiedFields === null
				? $entity->getField($field) !== null
				: in_array($field->value, $modifiedFields, true)
			;
			if ($isWritten && !$template->has($field))
			{
				return (new Result())->addError(
					new Error(
						'The bank requisite field is not available in the current template.',
						BankRequisiteError::FIELD_NOT_AVAILABLE,
						['field' => $field->value],
					),
				);
			}
		}

		return new Result();
	}

	private static function notFound(int $id): Result
	{
		return (new Result())->addError(
			new Error('Bank requisite not found.', BankRequisiteError::NOT_FOUND, ['id' => $id]),
		);
	}
}
