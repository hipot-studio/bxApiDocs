<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Internal\Entity\FormatPolicyRule;
use Bitrix\Disk\Internal\Model\EO_FormatPolicyRule;
use Bitrix\Disk\Internal\Model\FormatPolicyRuleTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\Json;
use Throwable;

class FormatPolicyRuleRepository
{
	public function findById(int $id): ?FormatPolicyRule
	{
		if ($id <= 0)
		{
			return null;
		}

		/** @var EO_FormatPolicyRule|null $ormModel */
		$ormModel
			= FormatPolicyRuleTable::query()
				->setSelect(['*'])
				->where('ID', $id)
				->setLimit(1)
				->fetchObject()
		;

		return $ormModel ? $this->convertFromOrm($ormModel) : null;
	}

	/**
	 * Finds the rule of an addressee whatever its scope is: an addressee has one rule at most.
	 */
	public function findByAddressee(string $entityType, int $entityId): ?FormatPolicyRule
	{
		/** @var EO_FormatPolicyRule|null $ormModel */
		$ormModel
			= FormatPolicyRuleTable::query()
				->setSelect(['*'])
				->where('ENTITY_TYPE', $entityType)
				->where('ENTITY_ID', $entityId)
				->setLimit(1)
				->fetchObject()
		;

		return $ormModel ? $this->convertFromOrm($ormModel) : null;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, FormatPolicyRule> Rules keyed by their ids.
	 */
	public function findByIds(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));

		if ($ids === [])
		{
			return [];
		}

		$ormModels
			= FormatPolicyRuleTable::query()
				->setSelect(['*'])
				->whereIn('ID', $ids)
				->fetchCollection()
		;

		$rules = [];
		foreach ($ormModels as $ormModel)
		{
			$rules[$ormModel->getId()] = $this->convertFromOrm($ormModel);
		}

		return $rules;
	}

	/**
	 * Returns a page of rules for the settings list. The rule of a deactivated employee is kept and
	 * still applies if the employee comes back, but the list does not show it by default.
	 *
	 * @return array{items: FormatPolicyRule[], total: int}
	 */
	public function list(int $offset, int $limit, bool $withInactiveUsers = false): array
	{
		$query = $this->buildListQuery($withInactiveUsers);

		$ormModels
			= $query
				->setSelect(['*'])
				->setOrder(['ID' => 'ASC'])
				->setOffset(max(0, $offset))
				->setLimit(max(1, $limit))
				->fetchCollection()
		;

		$items = [];
		foreach ($ormModels as $ormModel)
		{
			$items[] = $this->convertFromOrm($ormModel);
		}

		return [
			'items' => $items,
			'total' => (int)$this->buildListQuery($withInactiveUsers)->queryCountTotal(),
		];
	}

	/**
	 * Counts every rule of the portal, the ones of deactivated employees included.
	 */
	public function count(): int
	{
		return (int)FormatPolicyRuleTable::getCount();
	}

	/**
	 * Counts the rules the settings list shows: the ones of deactivated employees are left out,
	 * as the list leaves them out.
	 */
	public function countListed(): int
	{
		return (int)$this->buildListQuery(false)->queryCountTotal();
	}

	/**
	 * Inserts a new rule or updates an existing one and returns its id.
	 *
	 * @throws PersistenceException
	 */
	public function save(FormatPolicyRule $rule): int
	{
		try
		{
			$result = $this->convertToOrm($rule)->save();
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				message: 'Unable to save format policy rule',
				errors: $result->getErrorMessages(),
			);
		}

		$id = (int)$result->getId();
		$rule->setId($id);

		return $id;
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(int $id): void
	{
		try
		{
			$result = FormatPolicyRuleTable::delete($id);
		}
		catch (Throwable $exception)
		{
			throw new PersistenceException(
				message: $exception->getMessage(),
				previous: $exception,
			);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				message: 'Unable to delete format policy rule',
				errors: $result->getErrorMessages(),
			);
		}
	}

	/**
	 * Deletes the rule of an addressee and returns its id, or null when the addressee had none.
	 *
	 * @throws PersistenceException
	 */
	public function deleteByAddressee(string $entityType, int $entityId): ?int
	{
		$row
			= FormatPolicyRuleTable::query()
				->setSelect(['ID'])
				->where('ENTITY_TYPE', $entityType)
				->where('ENTITY_ID', $entityId)
				->setLimit(1)
				->fetch()
		;

		if (!$row)
		{
			return null;
		}

		$id = (int)$row['ID'];
		$this->delete($id);

		return $id;
	}

	/**
	 * Loads what the rule index is built of: every rule without its lists of extensions and presets,
	 * which may be long and are read only for the rules a user actually matched.
	 *
	 * @return array<int, array{
	 *     id: int,
	 *     entityType: string,
	 *     entityId: int,
	 *     includeNested: bool,
	 *     accessCode: string,
	 *     mode: string,
	 *     blockNoExtension: bool,
	 *     savedAt: int,
	 *     updatedBy: int,
	 * }>
	 */
	public function loadIndexRows(): array
	{
		$ormModels
			= FormatPolicyRuleTable::query()
				->setSelect([
					'ID',
					'ENTITY_TYPE',
					'ENTITY_ID',
					'INCLUDE_NESTED',
					'ACCESS_CODE',
					'MODE',
					'BLOCK_NO_EXTENSION',
					'SAVED_AT',
					'UPDATED_BY',
				])
				->setOrder(['ID' => 'ASC'])
				->fetchCollection()
		;

		$rows = [];
		foreach ($ormModels as $ormModel)
		{
			$rows[] = [
				'id' => $ormModel->getId(),
				'entityType' => $ormModel->getEntityType(),
				'entityId' => $ormModel->getEntityId(),
				'includeNested' => $ormModel->getIncludeNested(),
				'accessCode' => $ormModel->getAccessCode(),
				'mode' => $ormModel->getMode(),
				'blockNoExtension' => $ormModel->getBlockNoExtension(),
				'savedAt' => $ormModel->getSavedAt(),
				'updatedBy' => $ormModel->getUpdatedBy(),
			];
		}

		return $rows;
	}

	private function buildListQuery(bool $withInactiveUsers): Query
	{
		$query = FormatPolicyRuleTable::query();
		if ($withInactiveUsers)
		{
			return $query;
		}

		$query->registerRuntimeField(
			(new Reference(
				'USER',
				UserTable::class,
				Join::on('this.ENTITY_ID', 'ref.ID')
					->where('this.ENTITY_TYPE', FormatPolicyRule::ENTITY_TYPE_USER)
				,
			))->configureJoinType(Join::TYPE_LEFT),
		);

		return $query->where(
			Query::filter()
				->logic('or')
				->whereNot('ENTITY_TYPE', FormatPolicyRule::ENTITY_TYPE_USER)
				->where('USER.ACTIVE', 'Y'),
		);
	}

	private function convertToOrm(FormatPolicyRule $rule): EO_FormatPolicyRule
	{
		$id = $rule->getId();
		$ormModel
			= $id > 0
				? FormatPolicyRuleTable::wakeUpObject(['ID' => $id])
				: FormatPolicyRuleTable::createObject();

		$ormModel
			->setEntityType($rule->getEntityType())
			->setEntityId($rule->getEntityId())
			->setIncludeNested($rule->isIncludeNested())
			->setAccessCode($rule->getAccessCode())
			->setMode($rule->getMode())
			->setExtensions(Json::encode(array_values($rule->getExtensions())))
			->setPresets(Json::encode(array_values($rule->getPresets())))
			->setBlockNoExtension($rule->isBlockNoExtension())
			->setSavedAt($rule->getSavedAt())
			->setUpdatedBy($rule->getUpdatedBy())
		;

		return $ormModel;
	}

	private function convertFromOrm(EO_FormatPolicyRule $ormModel): FormatPolicyRule
	{
		return
			(new FormatPolicyRule())
				->setId($ormModel->getId())
				->setEntityType((string)$ormModel->getEntityType())
				->setEntityId((int)$ormModel->getEntityId())
				->setIncludeNested((bool)$ormModel->getIncludeNested())
				->setAccessCode((string)$ormModel->getAccessCode())
				->setMode((string)$ormModel->getMode())
				->setExtensions($this->decodeList((string)$ormModel->getExtensions()))
				->setPresets($this->decodeList((string)$ormModel->getPresets()))
				->setBlockNoExtension((bool)$ormModel->getBlockNoExtension())
				->setSavedAt((int)$ormModel->getSavedAt())
				->setUpdatedBy((int)$ormModel->getUpdatedBy())
		;
	}

	/**
	 * @return string[]
	 */
	private function decodeList(string $value): array
	{
		try
		{
			$list = Json::decode($value);
		}
		catch (Throwable)
		{
			return [];
		}

		return is_array($list) ? array_values(array_map('strval', array_filter($list, 'is_scalar'))) : [];
	}
}
