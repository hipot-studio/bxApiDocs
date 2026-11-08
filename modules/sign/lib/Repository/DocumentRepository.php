<?php

namespace Bitrix\Sign\Repository;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Document;
use Bitrix\Sign\Internal;
use Bitrix\Sign\Item;
use Bitrix\Sign\Model\ItemBinder\DocumentBinder;
use Bitrix\Sign\Type\Document\CreationSourceMetaKey;
use Bitrix\Sign\Type\Document\CreationSourceType;
use Bitrix\Sign\Type\Document\ExternalDateCreateSourceType;
use Bitrix\Sign\Type\Document\EntityType;
use Bitrix\Sign\Type\Document\ExternalIdSourceType;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Document\SchemeType;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\ProviderCode;

class DocumentRepository
{
	private const INITIATOR_NAME_META_KEY = Document::META_KEYS['initiatorName'];
	private const DOCUMENT_DEFAULT_PARTIES_COUNT = 2;
	private const TEMPLATE_REPRESENTATIVE_BATCH_SIZE = 300;
	private const SAFE_EXPORT_BATCH_SIZE = 300;

	/** @var array<DocumentScenario::*, int> */
	private const SCENARIO_NAME_TO_ID_MAP = [
		DocumentScenario::SIMPLE_SIGN_ONE_PARTY_MANY_MEMBERS => 1,
		DocumentScenario::SIMPLE_SIGN_MANY_PARTIES_ONE_MEMBERS => 2,
		DocumentScenario::DSS_ONE_PARTY_MANY_MEMBERS => 100,
		DocumentScenario::DSS_SECOND_PARTY_MANY_MEMBERS => 101,
	];

	/**
	 * @param Item\Document $item
	 *
	 * @return AddResult
	 */
	public function add(Item\Document $item): Result
	{
		$document = new Internal\Document();

		if ($item->uid !== null)
		{
			$document->setUid($item->uid);
		}
		$scenarioId = $item->scenario === null
			? null
			: $this->getScenarioIdByName($item->scenario)
		;
		$schemeId = $this->getSchemeIdByType($item->scheme);

		$now = new DateTime();
		$addResult = $document->setBlankId($item->blankId)
			->setTitle($item->title)
			->setStatus($item->status)
			->setLangId($item->langId)
			->setEntityId($item->entityId)
			->setEntityType($item->entityType)
			->setExternalId($item->externalId)
			->setMeta($this->getModelMetaByItem($item))
			->setCreatedById($item->createdById)
			->setModifiedById($item->createdById)
			->setDateCreate($now)
			->setDateModify($now)
			->setScenario($scenarioId)
			->setVersion($item->version)
			->setParties($item->parties ?? self::DOCUMENT_DEFAULT_PARTIES_COUNT)
			->setScheme($schemeId)
			->setProviderCode($item->providerCode)
			->setTemplateId($item->templateId)
			->setChatId($item->chatId)
			->setCreatedFromDocumentId($item->createdFromDocumentId)
			->setInitiatedByType($item->initiatedByType->toInt())
			->setHcmlinkCompanyId($item->hcmLinkCompanyId)
			->setDateSignUntil($item->dateSignUntil)
			->setDateStatusChanged($item->dateStatusChanged)
			->setGroupId($item->groupId)
			->setRepresentativeId($item->representativeId)
			->setExternalIdSourceType(($item->externalIdSourceType ?? ExternalIdSourceType::MANUAL)->toInt())
			->setExternalDateCreateSourceType(($item->externalDateCreateSourceType ?? ExternalDateCreateSourceType::MANUAL)->toInt())
			->setHcmlinkDateSettingId($item->hcmLinkDateSettingId)
			->setHcmlinkExternalIdSettingId($item->hcmLinkExternalIdSettingId)
			->setHcmlinkDocumentTypeSettingId($item->hcmLinkDocumentTypeSettingId)
			->setCreationSourceType(($item->creationSourceType ?? CreationSourceType::UNKNOWN)->toInt())
			->setCreationSourceId($item->creationSourceId)
			->setCreationSourceEntityTypeId($item->creationSourceEntityTypeId)
			->setCreationSourceEntityId($item->creationSourceEntityId)
			->setInviteMessage($item->inviteMessage)
			->save()
		;

		$item->id = $addResult->getId();

		if ($addResult->isSuccess())
		{
			$item->initOriginal();
		}

		return $addResult->setData(['document' => $item]);
	}

	/**
	 * @param Item\Document $item
	 *
	 * @return UpdateResult
	 */
	public function update(Item\Document $item): Result
	{
		return $this->updateAllowingNull($item);
	}

	/**
	 * Updates the document and writes null for the listed item properties.
	 * DocumentBinder skips null values for the most of the properties,
	 * so they can not be emptied through a plain update.
	 *
	 * @param string ...$nullableProperties item property names allowed to be written as null
	 *
	 * @return UpdateResult
	 */
	public function updateAllowingNull(Item\Document $item, string ...$nullableProperties): Result
	{
		if (!$item->id)
		{
			return (new UpdateResult())->addError(new Error('Document not found'));
		}
		$document = Internal\DocumentTable::getById($item->id)
			->fetchObject();

		if (!$document)
		{
			return (new UpdateResult())->addError(new Error('Document not found'));
		}

		$binder = new DocumentBinder($item, $document, $this, $nullableProperties);
		$binder->setChangedItemPropertiesToModel();

		if ($document->isUidChanged())
		{
			// Backward compatibility
			$document->setHash($item->uid);
		}

		if (isset($item->scenario))
		{
			$scenarioId = $this->getScenarioIdByName($item->scenario);
			if ($scenarioId === null)
			{
				return (new Result())->addError(new Error("Scenario with name: `{$item->scenario}` doesnt exist"));
			}
		}

		$document->setDateModify(new DateTime());
		
		$result = $document->save()
			->setData(['document' => $item])
		;

		if ($result->isSuccess())
		{
			$item->initOriginal();
		}
		
		return $result;
	}

	/**
	 * @param list<int> $documentIds
	 */
	public function resetRepresentativeByIds(array $documentIds, int $expectedRepresentativeId): Main\Result
	{
		$result = new Main\Result();
		if (empty($documentIds))
		{
			return $result;
		}

		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = Internal\DocumentTable::getTableName();
		[$update] = $helper->prepareUpdate($tableName, ['REPRESENTATIVE_ID' => null]);
		$sql = (new SqlExpression(
			'UPDATE ?# SET ' . $update . ' WHERE ?# IN (?@) AND ?# = ?i',
			$tableName,
			'ID',
			array_values(array_unique($documentIds)),
			'REPRESENTATIVE_ID',
			$expectedRepresentativeId,
		))->compile();
		$connection->queryExecute($sql);

		return $result;
	}

	/**
	 * @return \Generator<array{documentId: int, templateId: int}>
	 */
	public function iterateB2eTemplateDocumentIdsByRepresentative(int $userId): \Generator
	{
		$lastDocumentId = 0;
		do
		{
			$rows = Internal\DocumentTable::query()
				->setSelect(['ID', 'TEMPLATE_ID'])
				->where('REPRESENTATIVE_ID', $userId)
				->where('TEMPLATE_ID', '>', 0)
				->where('ENTITY_TYPE', EntityType::SMART_B2E)
				->where('ID', '>', $lastDocumentId)
				->setOrder(['ID' => 'ASC'])
				->setLimit(self::TEMPLATE_REPRESENTATIVE_BATCH_SIZE)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$lastDocumentId = (int)$row['ID'];

				yield [
					'documentId' => $lastDocumentId,
					'templateId' => (int)$row['TEMPLATE_ID'],
				];
			}
		}
		while (count($rows) === self::TEMPLATE_REPRESENTATIVE_BATCH_SIZE);
	}

	public function unsetEntityId(Item\Document $item): Result
	{
		if (!$item->id)
		{
			return (new UpdateResult())->addError(new Error('Document not found'));
		}

		$document = Internal\DocumentTable::getById($item->id)->fetchObject();

		if (!$document)
		{
			return (new UpdateResult())->addError(new Error('Document not found'));
		}

		return $document->setEntityId(0)->save();
	}

	public function getByUid(string $uid): ?Item\Document
	{
		$document = Internal\DocumentTable::query()
			->setSelect(['*'])
			->where('UID', $uid)
			->setLimit(1)
			->fetchObject();

		return $document === null ? null : $this->extractItemFromModel($document);
	}

	/**
	 * Returns the document of the group that has been waiting in the status the longest.
	 *
	 * @param int $afterId Skips the documents up to and including this id: a document the caller has
	 *                     given up on must not hold back the ones behind it.
	 */
	public function getFirstByGroupIdAndStatus(int $groupId, string $status, int $afterId = 0): ?Item\Document
	{
		if ($groupId < 1)
		{
			return null;
		}

		$query = Internal\DocumentTable::query()
			->setSelect(['*'])
			->where('GROUP_ID', $groupId)
			->where('STATUS', $status)
			->addOrder('ID')
			->setLimit(1)
		;
		if ($afterId > 0)
		{
			$query->where('ID', '>', $afterId);
		}

		$document = $query->fetchObject();

		return $document === null ? null : $this->extractItemFromModel($document);
	}

	public function getIdByUid(string $uid): ?int
	{
		$document = Internal\DocumentTable::query()
			->setSelect(['ID'])
			->where('UID', $uid)
			->setLimit(1)
			->fetch()
		;

		return $document['ID'] ?? null;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByHash(string $uid): ?Item\Document
	{
		$document = Internal\DocumentTable::query()
			->setSelect(['*'])
			->where('HASH', $uid)
			->setLimit(1)
			->fetchObject();

		return $document === null ? null : $this->extractItemFromModel($document);
	}

	public function getCountByBlankId(int $blankId): int
	{
		$res = Internal\DocumentTable::getList([
			'select' => [
				new \Bitrix\Main\Entity\ExpressionField(
					'CNT', 'COUNT(*)',
				),
			],
			'filter' => [
				'BLANK_ID' => $blankId,
			],
		]);

		if ($row = $res->fetch())
		{
			return $row['CNT'];
		}

		return 0;
	}

	public function listLastB2eFromCompanyByUserCreateId(int $id, int $limit = 10): Item\DocumentCollection
	{
		/** @var Internal\DocumentCollection $models */
		$models = Internal\DocumentTable::query()
			->addSelect('*')
			->where('CREATED_BY_ID', $id)
			->where('ENTITY_TYPE', EntityType::SMART_B2E)
			->where('INITIATED_BY_TYPE', InitiatedByType::COMPANY->toInt())
			->setLimit($limit)
			->addOrder('ID', 'DESC')
			->fetchCollection()
		;
		return $models === null
			? new Item\DocumentCollection()
			: $this->extractItemCollectionByModelCollection($models)
		;
	}

	/**
	 * @param string[] $statusList
	 * @see DocumentStatus
	 */
	public function listLastB2eNotInStatus(int $limit = 10, array $statusList = []): Item\DocumentCollection
	{
		$models = Internal\DocumentTable::query()
			->addSelect('*')
			->where('ENTITY_TYPE', EntityType::SMART_B2E)
			->where('INITIATED_BY_TYPE', InitiatedByType::COMPANY->toInt())
			->setLimit($limit)
			->addOrder('ID', 'DESC')
		;

		if (!empty($statusList))
		{
			$models->whereNotIn('STATUS', $statusList);
		}

		return $models === null
			? new Item\DocumentCollection()
			: $this->extractItemCollectionByModelCollection($models->fetchCollection())
		;
	}

	public function listLastByUserCreateId(int $id, int $limit = 10): Item\DocumentCollection
	{
		/** @var Internal\DocumentCollection $models */
		$models = Internal\DocumentTable::query()
			->addSelect('*')
			->where('CREATED_BY_ID', $id)
			->setLimit($limit)
			->addOrder('ID', 'DESC')
			->fetchCollection()
		;

		return $models === null
			? new Item\DocumentCollection()
			: $this->extractItemCollectionByModelCollection($models)
		;
	}

	public function listByGroupId(int $groupId, int $limit = 15): Item\DocumentCollection
	{
		if ($groupId < 1 || $limit < 1)
		{
			return new Item\DocumentCollection();
		}

		$models = Internal\DocumentTable::query()
			->addSelect('*')
			->where('GROUP_ID', $groupId)
			->setLimit($limit)
			->addOrder('ID', 'DESC')
			->fetchCollection()
		;

		return $this->extractItemCollectionByModelCollection($models);
	}

	/**
	 * Documents created from one source entity, narrowed by status. The pair of source columns is
	 * the whole index IX_B_SIGN_DOCUMENT_CREATION_SRC, the status stays a plain condition of the query;
	 * the specifics of the source live in the serialized META, so a caller that needs them compares
	 * them itself.
	 *
	 * Returns the newest $limit documents of the entity, never its whole history: an entity holds a
	 * handful of them, and the cap only guards against degenerate data. An older document beyond the
	 * limit is not returned even when it matches the filter.
	 *
	 * @param array<string> $statuses
	 * @see DocumentStatus
	 */
	public function listByCreationSourceEntity(
		int $entityTypeId,
		int $entityId,
		array $statuses,
		int $limit = 50,
	): Item\DocumentCollection
	{
		// an empty list of statuses matches nothing: read as "no filter" it would return the whole
		// history of the entity instead
		if ($entityTypeId < 1 || $entityId < 1 || $statuses === [] || $limit < 1)
		{
			return new Item\DocumentCollection();
		}

		$models = Internal\DocumentTable::query()
			->addSelect('*')
			->where('CREATION_SOURCE_ENTITY_TYPE_ID', $entityTypeId)
			->where('CREATION_SOURCE_ENTITY_ID', $entityId)
			->whereIn('STATUS', $statuses)
			->setLimit($limit)
			->addOrder('ID', 'DESC')
			->fetchCollection()
		;

		return $this->extractItemCollectionByModelCollection($models);
	}

	public function getCountByGroupId(int $groupId): int
	{
		if ($groupId < 1)
		{
			return 0;
		}

		return (int)Internal\DocumentTable::query()
			->addSelect('ID')
			->where('GROUP_ID', $groupId)
			->queryCountTotal()
		;
	}

	private function extractItemCollectionByModelCollection(Internal\DocumentCollection $models): Item\DocumentCollection
	{
		return new Item\DocumentCollection(
			...array_map([$this, 'extractItemFromModel'],
			$models->getAll(),
		));
	}

	private function extractItemFromModel(Internal\Document $model): Item\Document
	{
		$meta = $model->getMeta();
		$scenarioId = $model->getScenario();
		$scenario = $scenarioId === null ? $scenarioId : $this->getScenarioNameById($scenarioId);

		return new Item\Document(
			scenario:              $scenario,
			parties:               $model->getParties() ?? self::DOCUMENT_DEFAULT_PARTIES_COUNT,
			id:                    $model->getId(),
			title:                 $model->getTitle(),
			uid:                   $model->getUid(),
			blankId:               $model->getBlankId(),
			langId:                $model->getLangId(),
			status:                $model->getStatus(),
			initiator:             $meta['initiatorName'] ?? null,
			entityType:            $model->getEntityType(),
			entityTypeId:          EntityType::getEntityTypeIdByType($model->getEntityType()),
			entityId:              $model->getEntityId(),
			resultFileId:          $model->getResultFileId(),
			version:               $model->getVersion(),
			createdById:           $model->getCreatedById(),
			groupId: 			   $model->getGroupId(),
			companyEntityId:       $model->getCompanyEntityId(),
			companyUid:            $model->getCompanyUid(),
			representativeId:      $model->getRepresentativeId(),
			scheme:                $this->getSchemeTypeById($model->getScheme()),
			dateCreate:            $model->getDateCreate(),
			dateSign:              $model->getDateSign(),
			regionDocumentType:    $model->getRegionDocumentType(),
			externalId:            $model->getExternalId(),
			stoppedById:           $model->getStoppedById(),
			externalDateCreate:    $model->getExternalDateCreate(),
			providerCode:          $model->getProviderCode(),
			templateId:            $model->getTemplateId(),
			chatId:          	   $model->getChatId(),
			createdFromDocumentId: $model->getCreatedFromDocumentId(),
			initiatedByType:       InitiatedByType::tryFromInt($model->getInitiatedByType()) ?? InitiatedByType::COMPANY,
			hcmLinkCompanyId:      $model->getHcmlinkCompanyId(),
			dateSignUntil:         $model->getDateSignUntil(),
			dateStatusChanged:     $model->getDateStatusChanged(),
			externalDateCreateSourceType: ExternalDateCreateSourceType::tryFromInt($model->getExternalDateCreateSourceType()) ?? ExternalDateCreateSourceType::MANUAL,
			hcmLinkDateSettingId:       $model->getHcmlinkDateSettingId(),
			externalIdSourceType:       ExternalIdSourceType::tryFromInt($model->getExternalIdSourceType()) ?? ExternalIdSourceType::MANUAL,
			hcmLinkExternalIdSettingId: $model->getHcmlinkExternalIdSettingId(),
			hcmLinkDocumentTypeSettingId: $model->getHcmlinkDocumentTypeSettingId(),
			creationSourceType: CreationSourceType::tryFromInt($model->getCreationSourceType()) ?? CreationSourceType::UNKNOWN,
			creationSourceId: $model->getCreationSourceId(),
			creationSourceEntityTypeId: $model->getCreationSourceEntityTypeId(),
			creationSourceEntityId: $model->getCreationSourceEntityId(),
			creationSourceSpecifics: $this->extractCreationSourceSpecificsFromMeta($meta),
			inviteMessage:         $model->getInviteMessage(),
		);
	}

	/**
	 * @return array<string, int|string>
	 */
	private function extractCreationSourceSpecificsFromMeta(?array $meta): array
	{
		$specifics = [];
		foreach (CreationSourceMetaKey::cases() as $metaKey)
		{
			$value = $meta[$metaKey->value] ?? null;
			if ($value !== null)
			{
				$specifics[$metaKey->value] = $value;
			}
		}

		return $specifics;
	}

	public function getScenarioIdByName(string $scenarioName): ?int
	{
		return self::SCENARIO_NAME_TO_ID_MAP[$scenarioName] ?? null;
	}

	private function getScenarioNameById(int $scenarioId): ?string
	{
		$scenarioIdToNameMap = array_flip(self::SCENARIO_NAME_TO_ID_MAP);
		return $scenarioIdToNameMap[$scenarioId] ?? null;
	}

	public function getSchemeIdByType(?string $scheme): int
	{
		return SchemeType::SCHEME_TYPE_TO_ID_MAP[$scheme] ?? 0;
	}

	private function getSchemeTypeById(int $schemeId): string
	{
		$schemeIdToTypeMap = array_flip(SchemeType::SCHEME_TYPE_TO_ID_MAP);
		return $schemeIdToTypeMap[$schemeId] ?? SchemeType::DEFAULT;
	}

	public function getById(?int $id): ?Item\Document
	{
		$model = Internal\DocumentTable::getById($id)->fetchObject();
		return $model === null ? null : $this->extractItemFromModel($model);
	}

	public function getLastByUser(?int $userId): ?Item\Document
	{
		$document = Internal\DocumentTable::query()
			->setSelect(['*'])
			->where('CREATED_BY_ID', $userId)
			->setOrder('ID')
			->setLimit(1)
			->fetchObject();

		return $document === null ? null : $this->extractItemFromModel($document);
	}

	/**
	 * @param string[] $companyUids
	 */
	public function getLastCompanyProvidersByUser(
		int $userId,
		array $companyUids = [],
	): Item\ProviderDateCollection
	{
		if ($userId < 1)
		{
			return new Item\ProviderDateCollection();
		}

		$query = Internal\DocumentTable::query()
			->setSelect([
				'COMPANY_UID',
				'COMPANY_ENTITY_ID',
				new \Bitrix\Main\Entity\ExpressionField('LAST_USED', 'MAX(DATE_CREATE)'),
			])
			->where('CREATED_BY_ID', $userId)
			->where('DATE_CREATE', '>', (new DateTime())->add('-30 days'))
			->whereNotNull('PROVIDER_CODE')
			->whereNotNull('COMPANY_UID')
			->whereNotNull('COMPANY_ENTITY_ID')
			->setGroup(['COMPANY_UID', 'COMPANY_ENTITY_ID'])
		;

		if ($companyUids)
		{
			$query->whereIn('COMPANY_UID', $companyUids);
		}

		$rows = $query->fetchAll();

		return new Item\ProviderDateCollection(
			...array_map(
			static fn(array $row) => new Item\ProviderDate(
				companyUid: $row['COMPANY_UID'],
				companyId: $row['COMPANY_ENTITY_ID'],
				dateLastUsed: $row['LAST_USED'],
			),
			$rows,
		));
	}

	public function getModelMetaByItem(Item\Document $item): array
	{
		$documentModelMeta = [];

		if ($item->initiator !== null)
		{
			$documentModelMeta[self::INITIATOR_NAME_META_KEY] = $item->initiator;
		}

		foreach (CreationSourceMetaKey::cases() as $metaKey)
		{
			$value = $item->creationSourceSpecifics[$metaKey->value] ?? null;
			if ($value !== null)
			{
				$documentModelMeta[$metaKey->value] = $value;
			}
		}

		return $documentModelMeta;
	}

	/**
	 * Get last document by blank id
	 * @param int $blankId
	 *
	 * @return \Bitrix\Sign\Item\Document|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getLastByBlankId(int $blankId): ?Item\Document
	{
		$document = Internal\DocumentTable::query()
			->setSelect(['*'])
			->where('BLANK_ID', $blankId)
			->setOrder('ID')
			->setLimit(1)
			->fetchObject();

		return $document === null ? null : $this->extractItemFromModel($document);
	}

	public function getFirstCreatedB2eDocument(): ?Item\Document
	{
		$document = Internal\DocumentTable::query()
			->setSelect(['*'])
			->whereIn('SCENARIO', [
				self::SCENARIO_NAME_TO_ID_MAP[DocumentScenario::DSS_ONE_PARTY_MANY_MEMBERS],
				self::SCENARIO_NAME_TO_ID_MAP[DocumentScenario::DSS_SECOND_PARTY_MANY_MEMBERS],
			])
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetchObject()
		;

		return $document === null ? null : $this->extractItemFromModel($document);
	}

    public function delete(Item\Document $documentItem): DeleteResult
    {
        return Internal\DocumentTable::delete($documentItem->id);
    }

	public function listByIds(array $ids): Item\DocumentCollection
	{
		if (empty($ids))
		{
			return new Item\DocumentCollection();
		}

		$models = Internal\DocumentTable::query()
			->addSelect('*')
			->whereIn('ID', $ids)
			->fetchCollection()
		;
		return $models === null
			? new Item\DocumentCollection()
			: $this->extractItemCollectionByModelCollection($models)
		;
	}

	public function listForSafeExportByIds(array $ids): Item\DocumentCollection
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		)));
		if ($ids === [])
		{
			return new Item\DocumentCollection();
		}

		$documents = [];
		foreach (array_chunk($ids, self::SAFE_EXPORT_BATCH_SIZE) as $batchIds)
		{
			$rows = Internal\DocumentTable::query()
				->setSelect([
					'ID',
					'TITLE',
					'SCENARIO',
					'EXTERNAL_ID',
					'ENTITY_TYPE',
					'ENTITY_ID',
					'CREATED_BY_ID',
					'REPRESENTATIVE_ID',
					// Without it in the selection the type silently reads as COMPANY, and the
					// export never recognises an employee-initiated document the way the screen
					// does - see Service\B2e\AnnulmentTargetService.
					'INITIATED_BY_TYPE',
				])
				->whereIn('ID', $batchIds)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$scenarioId = $row['SCENARIO'] === null ? null : (int)$row['SCENARIO'];
				$entityType = $row['ENTITY_TYPE'];
				$documents[] = new Item\Document(
					scenario: $scenarioId === null ? null : $this->getScenarioNameById($scenarioId),
					id: (int)$row['ID'],
					title: $row['TITLE'],
					entityType: $entityType,
					entityTypeId: $entityType === null ? null : EntityType::getEntityTypeIdByType($entityType),
					entityId: $row['ENTITY_ID'] === null ? null : (int)$row['ENTITY_ID'],
					createdById: $row['CREATED_BY_ID'] === null ? null : (int)$row['CREATED_BY_ID'],
					representativeId: $row['REPRESENTATIVE_ID'] === null ? null : (int)$row['REPRESENTATIVE_ID'],
					externalId: $row['EXTERNAL_ID'],
					initiatedByType: InitiatedByType::tryFromInt((int)$row['INITIATED_BY_TYPE'])
						?? InitiatedByType::COMPANY,
				);
			}
		}

		return new Item\DocumentCollection(...$documents);
	}

	public function listByEntityIdsAndType(array $entityIds, string $entityType): Item\DocumentCollection
	{
		$models = Internal\DocumentTable::query()
			->addSelect('*')
			->whereIn('ENTITY_ID', $entityIds)
			->where('ENTITY_TYPE', $entityType)
			->fetchCollection()
		;
		return $models === null
			? new Item\DocumentCollection()
			: $this->extractItemCollectionByModelCollection($models);
	}

	public function getByEntityIdAndType(int $entityId, string $entityType): ?Item\Document
	{
		$document = Internal\DocumentTable::query()
			->setSelect(['*'])
			->where('ENTITY_ID', $entityId)
			->where('ENTITY_TYPE', $entityType)
			->setLimit(1)
			->fetchObject()
		;

		return $document === null ? null : $this->extractItemFromModel($document);
	}

	public function updateProviderCodeToDocumentsByCompanyUid(string $companyUid, string $providerCode, int $limit = 200): Main\Result
	{
		if ($limit <= 0)
		{
			return new Main\Result();
		}

		$documentIds = Internal\DocumentTable::query()
			->setSelect(['ID'])
			->where('COMPANY_UID', $companyUid)
			->where(
				Query::filter()->logic('or')
					->whereNull('PROVIDER_CODE')
					->whereNot('PROVIDER_CODE', $providerCode),
			)
			->setLimit($limit)
			->fetchAll()
		;
		$documentIds = array_column($documentIds, "ID");
		if (empty($documentIds))
		{
			return new Main\Result();
		}

		return Internal\DocumentTable::updateMulti($documentIds, ['PROVIDER_CODE' => $providerCode]);
	}

	/**
	 * Writes the message of the invitation only while the document may still be edited. The status is
	 * part of the `WHERE` clause on purpose: the caller judges a document it read earlier, while
	 * `ConfigureDocument` of the same document runs in parallel and moves it to `ready`. A check made
	 * before the write cannot keep the text out of a document whose sending has already started, a
	 * conditional write can.
	 *
	 * The write goes around the item binder: only the two columns below change, so the whole item does
	 * not have to be read and written back to make the condition part of the same statement.
	 *
	 * @return bool true when the document carries the requested message after the call
	 */
	public function updateInviteMessageIfEditable(int $documentId, ?string $message): bool
	{
		return $this->updateInviteMessageForEditableDocuments([$documentId], $message);
	}

	/**
	 * The same conditional write for a whole batch of a group send. The text of the batch is one, so
	 * the documents share a single statement instead of one statement each: the batch is written while
	 * the transaction of the caller holds the rows, and every statement saved there is lock time saved.
	 * The id list stays short — the batch is capped by `GroupService::MAX_DOCUMENT_COUNT`.
	 *
	 * The write is refused as a whole: the caller learns that not every document took the text, and
	 * rolls its transaction back. Which document refused it is not reported — the batch entry point
	 * names no document in its errors either, it checks the whole batch before the first write.
	 *
	 * @param int[] $documentIds
	 *
	 * @return bool true only when every requested document carries the requested message after the call
	 */
	public function updateInviteMessageForEditableDocuments(array $documentIds, ?string $message): bool
	{
		$documentIds = array_values(array_unique(array_filter(
			array_map('intval', $documentIds),
			static fn(int $documentId): bool => $documentId > 0,
		)));
		if ($documentIds === [])
		{
			return false;
		}

		$connection = Main\Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = Internal\DocumentTable::getTableName();

		// The message is user input and never reaches the query as anything but the escaped assignment
		// prepareUpdate() builds; the statuses are module constants and the ids are integers.
		[$update] = $sqlHelper->prepareUpdate($tableName, [
			'INVITE_MESSAGE' => $message,
			'DATE_MODIFY' => new DateTime(),
		]);
		$editableStatuses = implode(',', array_map(
			static fn(string $status): string => "'" . $sqlHelper->forSql($status) . "'",
			DocumentStatus::getEditable(),
		));

		$connection->queryExecute(
			'UPDATE ' . $sqlHelper->quote($tableName) . " SET {$update}"
			. ' WHERE ' . $sqlHelper->quote('ID') . ' IN (' . implode(',', $documentIds) . ')'
			. ' AND ' . $sqlHelper->quote('STATUS') . " IN ({$editableStatuses})"
		);

		if ($connection->getAffectedRowsCount() === count($documentIds))
		{
			return true;
		}

		// MySQL counts changed rows, not matched ones: storing the very same text within the same
		// second changes nothing and looks exactly like a refused write. Only the stored value tells
		// the two apart, and a document that already carries the requested text needs no write at all.
		$rows = Internal\DocumentTable::query()
			->setSelect(['ID', 'INVITE_MESSAGE'])
			->whereIn('ID', $documentIds)
			->fetchAll()
		;
		if (count($rows) !== count($documentIds))
		{
			return false;
		}

		foreach ($rows as $row)
		{
			if (($row['INVITE_MESSAGE'] ?? null) !== $message)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Writes the stop initiator only while the document has none. Two stops of the same document
	 * race before the service call, and the trace decides who the callback names in the legal log,
	 * so the trace of the first initiator must survive the second attempt.
	 *
	 * @return bool true only when this very call wrote the trace
	 */
	public function setStoppedByIdIfEmpty(int $documentId, int $userId): bool
	{
		if ($documentId <= 0 || $userId <= 0)
		{
			return false;
		}

		$connection = Main\Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$table = $sqlHelper->quote(Internal\DocumentTable::getTableName());
		$column = $sqlHelper->quote('STOPPED_BY_ID');

		$connection->queryExecute(
			"UPDATE {$table} SET {$column} = {$userId}"
			. " WHERE " . $sqlHelper->quote('ID') . " = {$documentId} AND {$column} IS NULL"
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Takes the stop initiator back only while the trace is still the expected one. The rollback is
	 * as conditional as the write: an initiator that belongs to someone else is never cleared.
	 *
	 * @return bool true only when this very call cleared the trace
	 */
	public function resetStoppedById(int $documentId, int $expectedUserId): bool
	{
		if ($documentId <= 0 || $expectedUserId <= 0)
		{
			return false;
		}

		$connection = Main\Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$table = $sqlHelper->quote(Internal\DocumentTable::getTableName());
		$column = $sqlHelper->quote('STOPPED_BY_ID');

		$connection->queryExecute(
			"UPDATE {$table} SET {$column} = NULL"
			. " WHERE " . $sqlHelper->quote('ID') . " = {$documentId} AND {$column} = {$expectedUserId}"
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * @param ProviderCode::* $providerCode
	 * @param SchemeType::* $scheme
	 *
	 * @return list<int>
	 */
	public function listIdsByProviderCodeAndScheme(string $providerCode, string $scheme, int $limit): array
	{
		if ($limit < 1)
		{
			return [];
		}

		$result = Internal\DocumentTable::query()
			->setSelect(['ID'])
			->where('PROVIDER_CODE', $providerCode)
			->where('SCHEME', $scheme)
			->setLimit($limit)
			->fetchAll()
		;
		$documentIds = array_column($result, "ID");

		return $documentIds;
	}

	/**
	 * @param array<int> $documentIds
	 * @param string $newScheme
	 *
	 * @return Result
	 */
	public function updateSchemeToDocumentIds(array $documentIds, string $newScheme): Result
	{
		if (empty($documentIds))
		{
			return new Result();
		}

		$documentIds = array_unique($documentIds);

		return Internal\DocumentTable::updateMulti($documentIds, ['SCHEME' => $this->getSchemeIdByType($newScheme)]);
	}

	/**
	 * @param array<int> $templateIds
	 */
	public function listByTemplateIds(array $templateIds): Item\DocumentCollection
	{
		if (empty($templateIds))
		{
			return new Item\DocumentCollection();
		}

		$models = Internal\DocumentTable::query()
			->setSelect(['*'])
			->whereIn('TEMPLATE_ID', $templateIds)
			->where('TEMPLATE.HIDDEN', false)
			->fetchCollection()
		;

		return $this->extractItemCollectionByModelCollection($models);
	}

	public function getByTemplateId(int $id): ?Item\Document
	{
		$document = Internal\DocumentTable::query()
			->setSelect(['*'])
			->where('TEMPLATE_ID', $id)
			->setLimit(1)
			->fetchObject()
		;

		return $document === null ? null : $this->extractItemFromModel($document);
	}

	public function getByTemplateIds(int ...$ids): Item\DocumentCollection
	{
		if (empty($ids))
		{
			return new Item\DocumentCollection();
		}

		$documents = Internal\DocumentTable::query()
			->setSelect(['*'])
			->whereIn('TEMPLATE_ID', $ids)
			->fetchCollection()
		;

		return $this->extractItemCollectionByModelCollection($documents);
	}

	public function deleteByTemplateId(int $id): Result
	{
		Internal\DocumentTable::deleteByFilter(
			[
				'=TEMPLATE_ID' => $id,
			],
		);

		return new Result();
	}

	/**
	 * @param array<int> $createdFromDocumentIds
	 */
	public function getByCreatedFromDocumentIdsAndInitiatedByTypeAndCreatedByIdOrderedByDateCreateDesc(
		array $createdFromDocumentIds,
		InitiatedByType $initiatedByType,
		int $createdById,
	): ?Item\Document
	{
		if (empty($createdFromDocumentIds))
		{
			return null;
		}

		$model = Internal\DocumentTable::query()
			->setSelect(['*'])
			->whereIn('CREATED_FROM_DOCUMENT_ID', $createdFromDocumentIds)
			->where('INITIATED_BY_TYPE', $initiatedByType->toInt())
			->where('CREATED_BY_ID', $createdById)
			->addOrder('ID', 'DESC')
			->fetchObject()
		;

		return $model === null
			? null
			: $this->extractItemFromModel($model)
		;
	}

	public function existAnyDocument(bool $withoutTemplates = true): bool
	{
		$query = Internal\DocumentTable::query()
			->setSelect(['ID'])
			->setLimit(1)
		;

		if ($withoutTemplates)
		{
			$query->whereNull('TEMPLATE_ID');
		}

		return $query->fetchObject() !== null;
	}

	public function listByBlankId(int $id): Item\DocumentCollection
	{
		$models = Internal\DocumentTable::query()
			->setSelect(['*'])
			->where('BLANK_ID', $id)
			->fetchCollection()
		;

		return $models === null
			? new Item\DocumentCollection()
			: $this->extractItemCollectionByModelCollection($models)
		;
	}

	public function listByUids(array $uids): Item\DocumentCollection
	{
		if (empty($uids))
		{
			return new Item\DocumentCollection();
		}

		$models = Internal\DocumentTable::query()
			->addSelect('*')
			->whereIn('UID', $uids)
			->fetchCollection()
		;
		return $models === null
			? new Item\DocumentCollection()
			: $this->extractItemCollectionByModelCollection($models)
		;
	}

	/**
	 * @param string[] $scenarios
	 */
	public function countByScenarios(array $scenarios): int
	{
		$scenarioIds = array_map([$this, 'getScenarioIdByName'], $scenarios);

		return (int)Internal\DocumentTable::query()
			->addSelect('ID')
			->whereIn('SCENARIO', $scenarioIds)
			->whereNull('TEMPLATE_ID')
			->queryCountTotal()
		;
	}
}
