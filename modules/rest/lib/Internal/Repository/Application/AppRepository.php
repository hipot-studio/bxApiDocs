<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Application;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
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
use Bitrix\Rest\Internal\Model\AppAccessTable;
use Bitrix\Rest\Internal\Model\AppAttributeTable;
use Bitrix\Rest\Internal\Model\AppFreeWhitelistTable;
use Bitrix\Rest\Internal\Model\AppLangTable;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\EO_App_Collection;
use Bitrix\Rest\Internal\Model\EO_AppAttribute_Collection;
use Bitrix\Rest\Internal\Model\EO_AppLang_Collection;
use Bitrix\Rest\Internal\Repository\Mapper\AppMapper;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingAccessSynchronizer;

class AppRepository implements RepositoryInterface
{
	public function __construct(
		private readonly AppMapper $mapper = new AppMapper(),
		private readonly AppAccessRepository $accessRepository = new AppAccessRepository(),
	)
	{
	}

	public function findMenuApplications(bool $mobile, int $appId = 0): EO_App_Collection
	{
		$query = AppTable::query()
			->setSelect([
				'ID',
				'CODE',
				'CLIENT_ID',
				'MENU_NAME' => 'LANG.MENU_NAME',
				'MENU_NAME_DEFAULT' => 'LANG_DEFAULT.MENU_NAME',
				'MENU_NAME_LICENSE' => 'LANG_LICENSE.MENU_NAME',
			])
			->where('ACTIVE', AppTable::ACTIVE)
			->setOrder(['ID' => 'ASC'])
		;
		if ($mobile)
		{
			$query->where('MOBILE', AppTable::ACTIVE);
		}
		if ($appId > 0)
		{
			$query->where('ID', $appId);
		}

		return $query->fetchCollection();
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
			$this->accessRepository->getByAppIds([(int)$ormModel->getId()]),
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
			$this->accessRepository->getByAppIds([(int)$ormModel->getId()]),
		);
	}

	public function isVersionInstalledByCode(string $code, string $version): bool
	{
		return AppTable::query()
			->setSelect(['ID'])
			->where('CODE', $code)
			->where('VERSION', $version)
			->where('ACTIVE', AppTable::ACTIVE)
			->where('INSTALLED', AppTable::INSTALLED)
			->setLimit(1)
			->fetch() !== false
		;
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
			$this->accessRepository->getByAppIds([(int)$ormModel->getId()]),
		);
	}

	public function getByIds(array $ids, ?AppSelect $select = null): AppCollection
	{
		if (empty($ids))
		{
			return new AppCollection();
		}

		return $this->getList(
			filter: (new AppFilter())->ids($ids),
			select: $select,
			pager: new AppPager(0, count($ids)),
		);
	}

	/**
	 * @return ?array<string, mixed>
	 */
	public function findRowById(int $id): ?array
	{
		return $this->findRow('ID', $id);
	}

	/**
	 * @return ?array<string, mixed>
	 */
	public function findRowByCode(string $code): ?array
	{
		return $this->findRow('CODE', $code);
	}

	/**
	 * @return ?array<string, mixed>
	 */
	public function findRowByClientId(string $clientId): ?array
	{
		return $this->findRow('CLIENT_ID', $clientId);
	}

	/**
	 * @return list<array{LANGUAGE_ID: string, MENU_NAME: ?string}>
	 */
	public function findLangRowsByAppId(int $appId): array
	{
		return AppLangTable::query()
			->setSelect(['LANGUAGE_ID', 'MENU_NAME'])
			->where('APP_ID', $appId)
			->setOrder(['LANGUAGE_ID' => 'ASC'])
			->fetchAll()
		;
	}

	/**
	 * @return ?array<string, mixed>
	 */
	private function findRow(string $ormField, int|string $value): ?array
	{
		return AppRowCache::remember(
			$ormField,
			$value,
			function () use ($ormField, $value): ?array {
				$row = AppTable::query()
					->setSelect(['*'])
					->where($ormField, $value)
					->setOrder(['ID' => 'ASC'])
					->setLimit(1)
					->fetch()
				;

				if ($row === false)
				{
					return null;
				}

				$row['ACCESS'] = implode(',', $this->accessRepository->getCodes((int)$row['ID']));

				return $row;
			},
		);
	}

	/**
	 * @param App $entity
	 *
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		$isNew = $entity->getId() === null;
		$shouldSaveAccess = $isNew || $entity->isAccessChanged();
		$shouldSyncEmbeddingAccess = $isNew || $entity->isEmbeddingAccessChanged();

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
		if ($shouldSaveAccess)
		{
			$this->saveAccess($entity);
		}
		if ($shouldSyncEmbeddingAccess)
		{
			(new EmbeddingAccessSynchronizer($this))->syncApp((int)$entity->getId());
		}

		$entity->markAccessChangesSaved();
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
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

		$access = $this->accessRepository->getByAppIds($appIds);

		return $this->mapper->convertCollectionFromOrm(
			$ormCollection,
			$this->resolveAttributesArg($select, $appIds),
			$this->resolveLangsArg($select, $appIds),
			$access,
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

	public function getCount(AppFilter $filter, int $cacheTtl = 0): int
	{
		$query = $this->buildFilterQuery($filter)
			->addSelect('ID')
			->countTotal(true)
		;
		if ($cacheTtl > 0)
		{
			$query->setCacheTtl($cacheTtl);
		}

		return (int)$query->exec()
			->getCount()
		;
	}

	/**
	 * @return array<int, array{ID: int, CODE: ?string}>
	 */
	public function getCodePage(AppFilter $filter, int $afterId, int $limit): array
	{
		$query = $this->buildFilterQuery($filter)
			->setSelect(['ID', 'CODE'])
			->where('ID', '>', $afterId)
			->addOrder('ID', 'ASC')
			->setLimit($limit)
		;

		return $query->fetchAll();
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

	public function exists(AppFilter $filter): bool
	{
		return $this->buildFilterQuery($filter)
			->setSelect(['ID'])
			->setLimit(1)
			->fetch() !== false;
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

		if ($filter->getAccessFilter() !== null)
		{
			$query->registerRuntimeField(
				new Reference(
					'ACCESS_OWNER',
					AppAttributeTable::class,
					Join::on('this.ID', 'ref.APP_ID')
						->where('ref.TYPE', AppAttribute::TYPE_SYSTEM)
						->where('ref.CODE', AppAttributeCode::OwnerUserId->value),
					Join::TYPE_LEFT,
				)
			);
			$query->where($this->buildAccessCondition($filter->getAccessFilter()));
		}

		$ids = $filter->getIds();
		if ($ids !== null)
		{
			if (empty($ids))
			{
				$query->whereNull('ID');
			}
			else
			{
				$query->whereIn('ID', $ids);
			}
		}

		return $query;
	}

	private function buildAccessCondition(AppAccessFilter $accessFilter): ConditionTree
	{
		$userId = $accessFilter->getUserId();
		$codes = $accessFilter->getAccessCodes();

		$condition = Query::filter()->logic('or');

		if (!empty($codes))
		{
			$condition->whereIn(
				'ID',
				AppAccessTable::query()
					->setSelect(['APP_ID'])
					->whereIn('ACCESS_CODE', $codes)
			);
		}

		$condition->where('ACCESS_OWNER.VALUE', (string)$userId);

		$condition->where(
			Query::filter()
				->whereNotIn('ID', AppAccessTable::query()->setSelect(['APP_ID']))
				->whereNull('ACCESS_OWNER.ID')
		);

		return $condition;
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

	/**
	 * @throws PersistenceException
	 */
	private function saveAccess(App $entity): void
	{
		$appId = $entity->getId();

		if ($appId === null)
		{
			return;
		}

		$access = $entity->getAccess();
		$codes = ($access === null || $access === '') ? [] : explode(',', $access);

		try
		{
			$this->accessRepository->replaceCodes($appId, $codes);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException('Unable to save app access codes', previous: $e);
		}
	}
}
