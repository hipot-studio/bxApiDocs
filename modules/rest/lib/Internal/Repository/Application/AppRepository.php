<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Rest\Internal\Entity\Application\AppAttribute;
use Bitrix\Rest\Internal\Entity\Application\AppAttributeCode;
use Bitrix\Rest\Internal\Entity\Application\AppCollection;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppLangCollection;
use Bitrix\Rest\Internal\Entity\Application\AppOrigin;
use Bitrix\Rest\Internal\Model\AppAttributeTable;
use Bitrix\Rest\Internal\Model\AppFreeWhitelistTable;
use Bitrix\Rest\Internal\Model\AppLangTable;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\EO_App_Collection;
use Bitrix\Rest\Internal\Model\EO_AppAttribute_Collection;
use Bitrix\Rest\Internal\Model\EO_AppLang_Collection;
use Bitrix\Rest\Internal\Repository\Mapper\AppMapper;

class AppRepository implements RepositoryInterface
{
	public function __construct(
		private readonly AppMapper $mapper = new AppMapper(),
	)
	{
	}

	public function getById(mixed $id): ?App
	{
		$ormModel = AppTable::getById($id)->fetchObject();

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm(
			$ormModel,
			$this->createAttributeLoader(),
			$this->createLangLoader(),
		);
	}

	public function getByClientId(string $clientId): ?App
	{
		$ormModel = AppTable::query()
			->setSelect(['*'])
			->where('CLIENT_ID', $clientId)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm(
			$ormModel,
			$this->createAttributeLoader(),
			$this->createLangLoader(),
		);
	}

	public function getByCode(string $code): ?App
	{
		$ormModel = AppTable::query()
			->setSelect(['*'])
			->where('CODE', $code)
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm(
			$ormModel,
			$this->createAttributeLoader(),
			$this->createLangLoader(),
		);
	}

	/**
	 * @param App $entity
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
			throw new PersistenceException('Unable to save application');
		}

		$this->saveAttributes($entity);
		$this->saveExternalAttributes($entity);
		$this->saveLangs($entity);
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		AppLangTable::deleteByAppId((int)$id);
		AppAttributeTable::deleteByAppId((int)$id);

		try
		{
			$result = AppTable::delete($id);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete application');
		}
	}

	public function getList(
		AppFilter $filter,
		?AppSelect $select = null,
		?AppSort $sort = null,
		?AppPager $pager = null,
	): AppCollection
	{
		$select ??= new AppSelect();
		$sort ??= new AppSort();
		$pager ??= new AppPager();

		$query = $this->buildFilterQuery($filter)
			->addSelect('*')
			->setOffset($pager->getOffset())
			->setLimit($pager->getLimit())
		;

		$orderBy = $sort->toArray();
		foreach ($orderBy as $field => $order)
		{
			$query->addOrder($field, $order);
		}

		/**  @var EO_App_Collection $ormCollection */
		$ormCollection = $query->fetchCollection();
		$appIds = $ormCollection->getIdList();

		return $this->mapper->convertCollectionFromOrm(
			$ormCollection,
			$this->resolveAttributesArg($select, $appIds),
			$this->resolveLangsArg($select, $appIds),
		);
	}

	private function resolveAttributesArg(
		AppSelect $select,
		array $appIds,
	): EO_AppAttribute_Collection|\Closure|null
	{
		if (!$select->hasAttributes())
		{
			return $this->createAttributeLoader();
		}

		if (empty($appIds))
		{
			return null;
		}

		return AppAttributeTable::query()
			->setSelect(['*'])
			->whereIn('APP_ID', $appIds)
			->fetchCollection()
		;
	}

	private function resolveLangsArg(
		AppSelect $select,
		array $appIds,
	): EO_AppLang_Collection|\Closure|null
	{
		if (!$select->hasLangs())
		{
			return $this->createLangLoader();
		}

		if (empty($appIds))
		{
			return null;
		}

		return AppLangTable::query()
			->setSelect(['*'])
			->whereIn('APP_ID', $appIds)
			->fetchCollection()
		;
	}

	public function getCount(AppFilter $filter): int
	{
		return (int)$this->buildFilterQuery($filter)
			->addSelect('ID')
			->countTotal(true)
			->exec()
			->getCount()
		;
	}

	public function getInstalledList(int $offset = 0, int $limit = 50): AppCollection
	{
		return $this->getList(
			filter: (new AppFilter())->active()->installed(),
			pager: new AppPager($offset, $limit),
		);
	}

	public function getInstalledByUserList(int $userId, int $offset = 0, int $limit = 50): AppCollection
	{
		return $this->getList(
			filter: (new AppFilter())
				->active()
				->installed()
				->ownerUserId($userId)
			,
			pager: new AppPager($offset, $limit),
		);
	}

	public function getPaidApplications(int $offset = 0, int $limit = 50): AppCollection
	{
		return $this->getList(
			filter: (new AppFilter())
				->active()
				->origin(AppOrigin::Marketplace)
				->isInFreeWhitelist(false),
			pager: new AppPager($offset, $limit),
		);
	}

	public function getInstalledAppsCount(): int
	{
		return $this->getCount(
			(new AppFilter())
				->active()
				->installed()
				->origin(AppOrigin::Marketplace)
			,
		);
	}

	private function createAttributeLoader(): \Closure
	{
		return function (int $appId): array {
			$ormAttributes = AppAttributeTable::query()
				->setSelect(['*'])
				->where('APP_ID', $appId)
				->fetchCollection()
			;

			return $this->mapper->splitAttributeCollectionFromOrm($ormAttributes);
		};
	}

	private function createLangLoader(): \Closure
	{
		return function (int $appId): AppLangCollection {
			$ormLangs = AppLangTable::query()
				->setSelect(['*'])
				->where('APP_ID', $appId)
				->fetchCollection()
			;

			$langCollection = new AppLangCollection();
			foreach ($ormLangs as $ormLang)
			{
				$langCollection->add($this->mapper->convertLangFromOrm($ormLang));
			}

			return $langCollection;
		};
	}

	private function buildFilterQuery(AppFilter $filter): Query
	{
		$query = AppTable::query();

		if ($filter->getActive() !== null)
		{
			$query->where('ACTIVE', $filter->getActive());
		}

		if ($filter->getInstalled() !== null)
		{
			$query->where('INSTALLED', $filter->getInstalled());
		}

		if ($filter->getCode() !== null)
		{
			$query->where('CODE', $filter->getCode());
		}

		$statuses = $filter->resolveStatuses();
		if ($statuses !== null)
		{
			$statusValues = array_map(fn($s) => $s->value, $statuses);
			$query->whereIn('STATUS', $statusValues);
		}

		if ($filter->getIsInFreeWhitelist() !== null)
		{
			$query->registerRuntimeField(
				new Reference(
					'FREE_APP',
					AppFreeWhitelistTable::class,
					Join::on('this.CODE', 'ref.APP_CODE'),
					Join::TYPE_LEFT,
				)
			);

			if ($filter->getIsInFreeWhitelist())
			{
				$query->whereNotNull('FREE_APP.APP_CODE');
			}
			else
			{
				$query->whereNull('FREE_APP.APP_CODE');
			}
		}

		if ($filter->getOwnerUserId() !== null)
		{
			$query->registerRuntimeField(
				new Reference(
					'APP_EVA',
					AppAttributeTable::class,
					Join::on('this.ID', 'ref.APP_ID'),
					Join::TYPE_INNER,
				)
			);
			$query->where('APP_EVA.TYPE', AppAttribute::TYPE_SYSTEM);
			$query->where('APP_EVA.CODE', AppAttributeCode::OwnerUserId->value);
			$query->where('APP_EVA.VALUE', (string)$filter->getOwnerUserId());
		}

		if ($filter->getAttributes() !== null)
		{
			$index = 0;
			foreach ($filter->getAttributes() as $code => $value)
			{
				$alias = 'APP_ATTR_' . $index;
				$query->registerRuntimeField(
					new Reference(
						$alias,
						AppAttributeTable::class,
						Join::on('this.ID', 'ref.APP_ID'),
						Join::TYPE_INNER,
					)
				);
				$query->where($alias . '.TYPE', AppAttribute::TYPE_EXTERNAL);
				$query->where($alias . '.CODE', $code);
				$query->where($alias . '.VALUE', $value);
				$index++;
			}
		}

		if ($filter->getWhere() !== null)
		{
			$query->where($filter->getWhere());
		}

		return $query;
	}

	/**
	 * @throws PersistenceException
	 */
	private function saveAttributes(App $entity): void
	{
		$appId = $entity->getId();

		if ($appId === null)
		{
			return;
		}

		$entityAttributes = $entity->getAttributes();
		$codes = [];
		$rows = [];

		foreach ($entityAttributes as $attribute)
		{
			$attribute->setAppId($appId);
			$codes[] = $attribute->getCode();
			$rows[] = [
				'APP_ID' => $appId,
				'TYPE' => AppAttribute::TYPE_SYSTEM,
				'CODE' => $attribute->getCode(),
				'VALUE' => $attribute->getValue(),
			];
		}

		if (!empty($rows))
		{
			$result = AppAttributeTable::addMergeMulti($rows, ignoreEvents: true);

			if (!$result->isSuccess())
			{
				throw new PersistenceException(
					'Unable to save attributes: ' . implode(', ', $result->getErrorMessages()),
				);
			}
		}

		AppAttributeTable::deleteOrphansByAppId($appId, AppAttribute::TYPE_SYSTEM, $codes);
	}

	/**
	 * @throws PersistenceException
	 */
	private function saveExternalAttributes(App $entity): void
	{
		$appId = $entity->getId();

		if ($appId === null)
		{
			return;
		}

		$entityAttributes = $entity->getExternalAttributes();
		$codes = [];
		$rows = [];

		foreach ($entityAttributes as $attribute)
		{
			$codes[] = $attribute->getCode();
			$rows[] = [
				'APP_ID' => $appId,
				'TYPE' => AppAttribute::TYPE_EXTERNAL,
				'CODE' => $attribute->getCode(),
				'VALUE' => $attribute->getValue(),
			];
		}

		if (!empty($rows))
		{
			$result = AppAttributeTable::addMergeMulti($rows, ignoreEvents: true);

			if (!$result->isSuccess())
			{
				throw new PersistenceException(
					'Unable to save external attributes: ' . implode(', ', $result->getErrorMessages()),
				);
			}
		}

		AppAttributeTable::deleteOrphansByAppId($appId, AppAttribute::TYPE_EXTERNAL, $codes);
	}

	/**
	 * @throws PersistenceException
	 */
	private function saveLangs(App $entity): void
	{
		$appId = $entity->getId();

		if ($appId === null)
		{
			return;
		}

		$existingLangs = [];
		$ormCollection = AppLangTable::query()
			->setSelect(['*'])
			->where('APP_ID', $appId)
			->fetchCollection()
		;

		foreach ($ormCollection as $ormModel)
		{
			$existingLangs[$ormModel->getLanguageId()] = $ormModel;
		}

		$entityLangs = $entity->getLangs();

		foreach ($entityLangs as $lang)
		{
			$lang->setAppId($appId);

			if (isset($existingLangs[$lang->getLanguageId()]))
			{
				$existing = $existingLangs[$lang->getLanguageId()];
				$lang->setId($existing->getId());
				unset($existingLangs[$lang->getLanguageId()]);
			}

			try
			{
				$result = $this->mapper->convertLangToOrm($lang)->save();
			}
			catch (\Exception $e)
			{
				throw new PersistenceException(
					'Unable to save lang ' . $lang->getLanguageId(),
					previous: $e
				);
			}

			if ($result->isSuccess() && !$lang->getId())
			{
				$lang->setId($result->getId());
			}
		}

		foreach ($existingLangs as $orphan)
		{
			AppLangTable::delete($orphan->getId());
		}
	}
}
