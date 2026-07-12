<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\APAuth\PermissionTable;
use Bitrix\Rest\Enum\APAuth\PasswordType;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookAttribute;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookAttributeCollection;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookCollection;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhookExternalAttribute;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Model\IncomingWebhookAttributeTable;
use Bitrix\Rest\Internal\Repository\IncomingWebhook\WebhookFilter;
use Bitrix\Rest\Internal\Repository\IncomingWebhook\WebhookPager;
use Bitrix\Rest\Internal\Repository\IncomingWebhook\WebhookSort;
use Bitrix\Rest\Internal\Repository\Mapper\IncomingWebhookMapper;

class IncomingWebhookRepository implements IncomingWebhookRepositoryInterface
{
	public function __construct(
		private readonly IncomingWebhookMapper $mapper = new IncomingWebhookMapper(),
	)
	{
	}

	public function getById(mixed $id): ?IncomingWebhook
	{
		$ormModel = PasswordTable::query()
			->setSelect(['*'])
			->where('ID', $id)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		$passwordId = (int)$ormModel->getId();
		$scopes = $this->getScopes($passwordId);
		$attributes = $this->getExternalAttributes($passwordId);

		return $this->mapper->convertFromOrm($ormModel, $scopes, $attributes);
	}

	public function getByWebhookId(string $webhookId): ?IncomingWebhook
	{
		$ormModel = PasswordTable::query()
			->setSelect(['*'])
			->where('PASSWORD', $webhookId)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		$passwordId = (int)$ormModel->getId();
		$scopes = $this->getScopes($passwordId);
		$attributes = $this->getExternalAttributes($passwordId);

		return $this->mapper->convertFromOrm($ormModel, $scopes, $attributes);
	}

	/**
	 * @return IncomingWebhook[]
	 */
	public function getListByUser(int $userId, int $offset = 0, int $limit = 50): array
	{
		$ormModels = PasswordTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
			->where('TYPE', PasswordType::User->value)
			->addOrder('ID', 'ASC')
			->setOffset($offset)
			->setLimit($limit)
			->fetchCollection()
		;

		$passwordIds = [];
		foreach ($ormModels as $ormModel)
		{
			$passwordIds[] = $ormModel->getId();
		}

		$scopesByPassword = $this->getScopesBatch($passwordIds);
		$attributesByPassword = $this->getExternalAttributesBatch($passwordIds);

		$result = [];
		foreach ($ormModels as $ormModel)
		{
			$passwordId = (int)$ormModel->getId();
			$scopes = $scopesByPassword[$passwordId] ?? [];
			$attributes = $attributesByPassword[$passwordId] ?? new IncomingWebhookAttributeCollection();
			$result[] = $this->mapper->convertFromOrm($ormModel, $scopes, $attributes);
		}

		return $result;
	}

	public function getList(
		WebhookFilter $filter,
		?WebhookSort $sort = null,
		?WebhookPager $pager = null,
	): IncomingWebhookCollection
	{
		$sort ??= new WebhookSort();
		$pager ??= new WebhookPager();

		$query = $this->buildFilterQuery($filter)
			->setSelect(['*'])
			->setOffset($pager->getOffset())
			->setLimit($pager->getLimit())
		;

		$orderBy = $sort->isEmpty() ? ['ID' => 'DESC'] : $sort->toArray();
		foreach ($orderBy as $field => $order)
		{
			$query->addOrder($field, $order);
		}

		$ormModels = $query->fetchCollection();

		$passwordIds = [];
		foreach ($ormModels as $ormModel)
		{
			$passwordIds[] = (int)$ormModel->getId();
		}

		$scopesByPassword = $this->getScopesBatch($passwordIds);
		$attributesByPassword = $this->getExternalAttributesBatch($passwordIds);

		$collection = new IncomingWebhookCollection();
		foreach ($ormModels as $ormModel)
		{
			$passwordId = (int)$ormModel->getId();
			$scopes = $scopesByPassword[$passwordId] ?? [];
			$attributes = $attributesByPassword[$passwordId] ?? new IncomingWebhookAttributeCollection();
			$collection->add($this->mapper->convertFromOrm($ormModel, $scopes, $attributes));
		}

		return $collection;
	}

	public function getCount(WebhookFilter $filter): int
	{
		return (int)$this->buildFilterQuery($filter)
			->addSelect('ID')
			->countTotal(true)
			->exec()
			->getCount()
		;
	}

	private function buildFilterQuery(WebhookFilter $filter): Query
	{
		$query = PasswordTable::query();

		$userId = $filter->getUserId();
		if ($userId !== null)
		{
			$query->where('USER_ID', $userId);
		}

		$type = $filter->getType();
		if ($type !== null)
		{
			$query->where('TYPE', $type->value);
		}

		$scopes = $filter->getScopes();
		if ($scopes !== null)
		{
			$query->whereIn('ID', PermissionTable::query()
				->setSelect(['PASSWORD_ID'])
				->whereIn('PERM', $scopes))
			;
		}

		$attributes = $filter->getExternalAttributes();
		if ($attributes !== null)
		{
			$query->whereIn('ID', $this->buildPasswordIdsByExternalAttributesQuery($attributes));
		}

		return $query;
	}

	private function buildPasswordIdsByExternalAttributesQuery(array $attributes): Query
	{
		$codeFilter = Query::filter()->logic('or');
		foreach ($attributes as $code => $value)
		{
			$condition = Query::filter()->where('CODE', $code);
			if ($value !== null)
			{
				$condition->where('VALUE', $value);
			}
			$codeFilter->where($condition);
		}

		return IncomingWebhookAttributeTable::query()
			->setSelect(['PASSWORD_ID'])
			->where('TYPE', IncomingWebhookAttribute::TYPE_EXTERNAL)
			->where($codeFilter)
			->setGroup(['PASSWORD_ID'])
			->registerRuntimeField(new ExpressionField('MATCHED_CODES', 'COUNT(DISTINCT %s)', 'CODE'))
			->where('MATCHED_CODES', count($attributes))
		;
	}

	/**
	 * @param IncomingWebhook $entity
	 *
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		try
		{
			$result = $this->mapper->convertToOrm($entity)->save();
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if ($result->isSuccess() && !$entity->getId())
		{
			$entity->setId($result->getId());
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to save incoming webhook');
		}

		$this->saveExternalAttributes($entity);
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		try
		{
			$result = PasswordTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete incoming webhook');
		}
	}

	private function getScopes(int $passwordId): array
	{
		$permissions = PermissionTable::query()
			->setSelect(['PERM'])
			->where('PASSWORD_ID', $passwordId)
			->fetchAll()
		;

		return array_column($permissions, 'PERM');
	}

	private function getExternalAttributes(int $passwordId): IncomingWebhookAttributeCollection
	{
		$ormAttributes = IncomingWebhookAttributeTable::query()
			->setSelect(['*'])
			->where('PASSWORD_ID', $passwordId)
			->where('TYPE', IncomingWebhookAttribute::TYPE_EXTERNAL)
			->fetchCollection()
		;

		$collection = new IncomingWebhookAttributeCollection();
		foreach ($ormAttributes as $attribute)
		{
			$collection->add(
				(new IncomingWebhookExternalAttribute(
					(int)$attribute->getPasswordId(),
					$attribute->getCode(),
					$attribute->getValue(),
				))
					->setId((int)$attribute->getId()),
			);
		}

		return $collection;
	}

	private function getScopesBatch(array $passwordIds): array
	{
		if (empty($passwordIds))
		{
			return [];
		}

		$permissions = PermissionTable::query()
			->setSelect(['PASSWORD_ID', 'PERM'])
			->whereIn('PASSWORD_ID', $passwordIds)
			->fetchAll()
		;

		$result = [];
		foreach ($permissions as $permission)
		{
			$result[(int)$permission['PASSWORD_ID']][] = $permission['PERM'];
		}

		return $result;
	}

	/**
	 * @param int[] $passwordIds
	 *
	 * @return array<int, IncomingWebhookAttributeCollection>
	 */
	private function getExternalAttributesBatch(array $passwordIds): array
	{
		if (empty($passwordIds))
		{
			return [];
		}

		$ormAttributes = IncomingWebhookAttributeTable::query()
			->setSelect(['*'])
			->whereIn('PASSWORD_ID', $passwordIds)
			->where('TYPE', IncomingWebhookAttribute::TYPE_EXTERNAL)
			->fetchCollection()
		;

		$result = [];
		foreach ($ormAttributes as $attribute)
		{
			$passwordId = (int)$attribute->getPasswordId();
			$result[$passwordId] ??= new IncomingWebhookAttributeCollection();
			$result[$passwordId]->add(
				(new IncomingWebhookExternalAttribute(
					$passwordId,
					$attribute->getCode(),
					$attribute->getValue(),
				))
					->setId((int)$attribute->getId()),
			);
		}

		return $result;
	}

	/**
	 * @throws PersistenceException
	 */
	private function saveExternalAttributes(IncomingWebhook $entity): void
	{
		$passwordId = $entity->getId();
		if ($passwordId === null)
		{
			return;
		}

		$codes = [];
		$rows = [];

		foreach ($entity->getExternalAttributes() as $attribute)
		{
			$codes[] = $attribute->getCode();
			$rows[] = [
				'PASSWORD_ID' => $passwordId,
				'TYPE' => IncomingWebhookAttribute::TYPE_EXTERNAL,
				'CODE' => $attribute->getCode(),
				'VALUE' => $attribute->getValue(),
			];
		}

		if (!empty($rows))
		{
			$result = IncomingWebhookAttributeTable::addMergeMulti($rows, ignoreEvents: true);
			if (!$result->isSuccess())
			{
				throw new PersistenceException(
					'Unable to save incoming webhook external attributes: '
					. implode(', ', $result->getErrorMessages()),
				);
			}
		}

		IncomingWebhookAttributeTable::deleteOrphansByPasswordId(
			$passwordId,
			IncomingWebhookAttribute::TYPE_EXTERNAL,
			$codes,
		);
	}
}
