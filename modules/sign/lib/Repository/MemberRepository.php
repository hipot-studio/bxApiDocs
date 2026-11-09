<?php

namespace Bitrix\Sign\Repository;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Entity\UpdateResult;
use Bitrix\Main\Error;
use Bitrix\Main;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Security\Random;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Sign\FeatureResolver;
use Bitrix\Sign\Internal;
use Bitrix\Sign\Item;
use Bitrix\Sign\Model\ItemBinder\MemberBinder;
use Bitrix\Sign\Result\Repository\Member\AnnulmentSwitchResult;
use Bitrix\Sign\Service\Cache\Memory\Sign\UserCache;
use Bitrix\Sign\Type;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Notification\ReminderType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;

class MemberRepository
{
	public const SIGN_DOCUMENT_LIST_QUERY_REF_FIELD_NAME_COMPANY = 'REF_COMPANY';
	private const SAFE_FOLDER_PARENT_COLUMN = 'SAFE_FOLDER_RELATION.PARENT_ID';

	private const TEMPLATE_USER_RELATION_BATCH_SIZE = 300;

	// Same batch as DocumentRepository::listForSafeExportByIds(): both run over the very
	// same set of documents, and the safe export feeds up to SAFE_EXPORT_LIMIT of them.
	private const SIGNERS_BY_DOCUMENTS_BATCH_SIZE = 300;
	private const MAX_SOURCE_USER_COUNT = 1000;
	private const VALIDATION_CRM_DISPATCH_META_KEY = 'sign.validation_crm_dispatch.v1';
	private const VALIDATION_CRM_DISPATCH_PENDING = 'pending';
	private const VALIDATION_CRM_DISPATCH_HANDLED = 'handled';

	// lower bound of an "empty" signing date: SQL NULL or dates below this
	// threshold (e.g. the zero seed date) are treated as an unset signing date.
	private const MY_SAFE_EMPTY_SIGN_DATE_THRESHOLD = '1970-01-01 00:00:00';

	// fail-safe order for the company safe grid: newest rows first.
	private const MY_SAFE_DEFAULT_ORDER = ['ID' => 'DESC'];

	/**
	 * Fields preloaded into UserCache; unlisted fields silently read as null from cached models.
	 *
	 * @var list<string>
	 */
	public const USER_CACHE_FIELDS = [
		'ID',
		'NAME',
		'SECOND_NAME',
		'LAST_NAME',
		'LOGIN',
		'PERSONAL_GENDER',
	];

	private ?UserCache $userCache = null;

	/**
	 * @param \Bitrix\Sign\Item\Member $item
	 *
	 * @return \Bitrix\Main\Result
	 */
	public function add(Item\Member $item): Main\Result
	{
		$item->uid = $this->generateUniqueUid();

		$now = new DateTime();
		$filledMemberEntity = $this
			->extractModelFromItem($item)
			->setDateCreate($now)
			->setDateModify($now)
		;

		$saveResult = $filledMemberEntity->save();

		if (!$saveResult->isSuccess())
		{
			return (new Main\Result())->addErrors($saveResult->getErrors());
		}

		$item->id = $saveResult->getId();
		$item->initOriginal();

		return (new Main\Result())->setData(['member' => $item]);
	}

	public function deleteById(int $id)
	{
		Internal\MemberTable::delete($id);
	}

	/**
	 * @param list<int> $ids
	 */
	public function deleteByIdsForUser(array $ids, int $userId): Main\Result
	{
		if (empty($ids))
		{
			return new Main\Result();
		}

		try
		{
			Internal\MemberTable::deleteByFilter([
				'@ID' => $ids,
				'=ENTITY_TYPE' => EntityType::USER,
				'=ENTITY_ID' => $userId,
				'@ROLE' => [
					$this->convertRoleToInt(Role::REVIEWER),
					$this->convertRoleToInt(Role::EDITOR),
				],
			]);
		}
		catch (Main\ArgumentException $e)
		{
			return (new Main\Result())->addError(new Main\Error($e->getMessage()));
		}

		return new Main\Result();
	}

	public function deleteAllByDocumentId(int $documentId): Main\Result
	{
		try
		{
			Internal\MemberTable::deleteByFilter([
				'=DOCUMENT_ID' => $documentId,
			]);
		}
		catch (Main\ArgumentException $e)
		{
			return (new Main\Result())->addError(new Main\Error($e->getMessage()));
		}

		return new Main\Result();
	}

	/**
	 * @param \Bitrix\Sign\Internal\Member|null $model
	 *
	 * @return \Bitrix\Sign\Item\Member
	 */
	private function extractItemFromModel(
		?Internal\Member $model,
		?Main\EO_User $user = null,
		bool $loadEntityNames = true,
	): Item\Member
	{
		if (!$model)
		{
			return new Item\Member();
		}

		$name = $loadEntityNames
			? match ($model->getEntityType())
			{
				EntityType::CONTACT => self::getCrmContactName($model->getEntityId()),
				EntityType::USER => self::getNameForUser($model->getEntityId(), $user),
				default => null,
			}
			: null
		;

		// b2e assignee or b2b first party
		$companyName = $loadEntityNames && $model->getEntityType() === EntityType::COMPANY
			? self::getCrmCompanyName($model->getEntityId())
			: null
		;

		$roleNumber = $model->getRole();
		$party = $model->getPart();
		if ($roleNumber === null)
		{
			$role = \Bitrix\Sign\Compatibility\Role::createByParty($party);
		}
		else
		{
			$role = $this->convertIntToRole($roleNumber);
		}

		return new Item\Member(
			documentId: $model->getDocumentId(),
			party: $party,
			id: $model->getId(),
			uid: $model->getUid(),
			status: $model->getSigned(),
			name: $name,
			companyName: $companyName,
			channelType: $model->getCommunicationType(),
			channelValue: $model->getCommunicationValue(),
			dateSigned: $model->getDateSign(),
			dateCreated: $model->getDateCreate(),
			entityType: $model->getEntityType(),
			entityId: $model->getEntityId(),
			presetId: $model->getPresetId(),
			signatureFileId: $model->getSignatureFileId(),
			stampFileId: $model->getStampFileId(),
			role: $role,
			signingMethod: $model->getSigningMethod(),
			configured: $model->getConfigured(),
			reminder: new Item\Member\Reminder(
				lastSendDate: $model->getReminderLastSendDate(),
				plannedNextSendDate: $model->getReminderPlannedNextSendDate(),
				completed: $model->getReminderCompleted(),
				type: ReminderType::tryFromInt($model->getReminderType()) ?? ReminderType::NONE,
				startDate: $model->getReminderStartDate(),
			),
			dateSend: $model->getDateSend(),
			employeeId: $model->getEmployeeId(),
			hcmLinkJobId: $model->getHcmlinkJobId(),
			dateStatusChanged: $model->getDateStatusChanged(),
			folderId: $model->collectValues()['FOLDER_ID'] ?? null,
			createdById: $model->getCreatedById(),
			annulled: (bool)$model->getAnnulled(),
			annulledById: $model->getAnnulledById(),
			dateAnnulled: $model->getDateAnnulled(),
		);
	}

	/**
	 * @param \Bitrix\Sign\Item\Member $item
	 *
	 * @return \Bitrix\Sign\Internal\Member
	 */
	private function extractModelFromItem(Item\Member $item): Internal\Member
	{
		return $this->getFilledModelFromItem($item);
	}

	/**
	 * @param \Bitrix\Sign\Internal\MemberCollection $modelCollection
	 *
	 * @return \Bitrix\Sign\Item\MemberCollection
	 */
	private function extractItemCollectionFromModelCollection(
		Internal\MemberCollection $modelCollection,
		bool $loadEntityNames = true,
	): Item\MemberCollection
	{
		$models = $modelCollection->getAll();

		$users = $loadEntityNames ? $this->getUserModels($modelCollection) : [];
		if ($loadEntityNames)
		{
			$this->userCache?->setCache($users);
			$this->userCache?->setCachedFields(self::USER_CACHE_FIELDS);
		}

		$items = array_map(
			fn(Internal\Member $member) => $this->extractItemFromModel(
				$member,
				$users[$member->getEntityId()] ?? null,
				$loadEntityNames,
			),
			$models,
		);

		return new Item\MemberCollection(...$items);
	}

	/**
	 * @param \Bitrix\Sign\Item\Member $item
	 *
	 * @return \Bitrix\Sign\Internal\Member
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getFilledModelFromItem(Item\Member $item): Internal\Member
	{
		$model = Internal\MemberTable::createObject(true);

		return $model
			->setCommunicationValue($item->channelValue)
			->setCommunicationType($item->channelType)
			->setPart($item->party)
			->setRole($this->convertRoleToInt($item->role ?? \Bitrix\Sign\Compatibility\Role::createByParty($item->party)))
			->setDocumentId($item->documentId)
			->setPresetId($item->presetId)
			->setEntityId($item->entityId)
			->setEntityType($item->entityType)
			->setHash($item->uid)
			->setStampFileId($item->stampFileId)
			->setSignatureFileId($item->signatureFileId)
			->setModifiedById(Main\Engine\CurrentUser::get()->getId())
			->setCreatedById(Main\Engine\CurrentUser::get()->getId())
			->setSigningMethod($item->signingMethod)
			->setContactId($item->entityType === EntityType::CONTACT ? $item->entityId : 0)
			->setMute('N')
			->setSigned($item->status)
			->setVerified('N')
			->setConfigured($item->configured)
			->setReminderStartDate($item->reminder->startDate)
			->setReminderType($item->reminder->type->toInt())
			->setReminderLastSendDate($item->reminder->lastSendDate)
			->setReminderPlannedNextSendDate($item->reminder->plannedNextSendDate)
			->setReminderCompleted($item->reminder->completed)
			->setEmployeeId($item->employeeId)
			->setHcmlinkJobId($item->hcmLinkJobId)
			->setDateStatusChanged($item->dateStatusChanged)
		;
	}

	public function listByDocumentIdWithParty(int $documentId, int $party, int $limit = 0): Item\MemberCollection
	{
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->where('PART', $party)
		;
		if ($limit)
		{
			$models->setLimit($limit);
		}

		return $this->extractItemCollectionFromModelCollection($models->fetchCollection());
	}

	public function listMembersByDocumentIdAndUserIds(int $documentId, int $representativeId, int ...$userIds): Item\MemberCollection
	{
		if (empty($userIds))
		{
			return new Item\MemberCollection();
		}

		$filter = Query::filter()
		   ->logic('or')
		   ->where(
			   Query::filter()
					->logic('and')
					->where('ENTITY_TYPE', '=', EntityType::USER)
					->whereIn('ENTITY_ID', $userIds),
		   )
		;

		if (in_array($representativeId, $userIds, true))
		{
			$filter->where(
				Query::filter()
					 ->logic('and')
					 ->where('ENTITY_TYPE', '=', EntityType::COMPANY)
					 ->where('ROLE', '=', $this->convertRoleToInt(Role::ASSIGNEE))
					 ->where('DOCUMENT.REPRESENTATIVE_ID', '=', $representativeId),
			);
		}

		$models = Internal\MemberTable::query()
			->setSelect(['*'])
			->where('DOCUMENT_ID', $documentId)
			->where($filter)
		;

		return $this->extractItemCollectionFromModelCollection($models->fetchCollection());
	}

	/**
	 * @return \Generator<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}>
	 */
	public function iterateDirectTemplateUserRelations(int $userId): \Generator
	{
		// Each role is walked separately: with ROLE fixed by equality the index
		// (ENTITY_ID, ENTITY_TYPE, ROLE) also serves the keyset order, so no sorting is needed.
		foreach ([Role::REVIEWER, Role::EDITOR] as $role)
		{
			yield from $this->iterateDirectTemplateUserRelationsByRole($userId, $role);
		}
	}

	/**
	 * @return \Generator<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}>
	 */
	private function iterateDirectTemplateUserRelationsByRole(int $userId, string $role): \Generator
	{
		$lastMemberId = 0;
		do
		{
			$rows = Internal\MemberTable::query()
				->setSelect([
					'MEMBER_ID' => 'ID',
					'DOCUMENT_ID',
					'TEMPLATE_ID' => 'DOCUMENT.TEMPLATE_ID',
				])
				->where('ENTITY_TYPE', EntityType::USER)
				->where('ENTITY_ID', $userId)
				->where('ROLE', $this->convertRoleToInt($role))
				->where('ID', '>', $lastMemberId)
				->where('DOCUMENT.TEMPLATE_ID', '>', 0)
				->where('DOCUMENT.ENTITY_TYPE', Type\Document\EntityType::SMART_B2E)
				->setOrder(['ID' => 'ASC'])
				->setLimit(self::TEMPLATE_USER_RELATION_BATCH_SIZE)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$lastMemberId = (int)$row['MEMBER_ID'];

				yield $this->createTemplateUserRelation($row);
			}
		}
		while (count($rows) === self::TEMPLATE_USER_RELATION_BATCH_SIZE);
	}

	/**
	 * @param list<int> $documentIds
	 * @return list<int>
	 */
	public function filterDocumentIdsWithCompanyAssignee(array $documentIds): array
	{
		if (empty($documentIds))
		{
			return [];
		}

		$rows = Internal\MemberTable::query()
			->setSelect(['DOCUMENT_ID'])
			->whereIn('DOCUMENT_ID', $documentIds)
			->where('ENTITY_TYPE', EntityType::COMPANY)
			->where('ROLE', $this->convertRoleToInt(Role::ASSIGNEE))
			->setDistinct()
			->fetchAll()
		;

		return array_map('intval', array_column($rows, 'DOCUMENT_ID'));
	}

	/**
	 * @param list<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}> $relations
	 * @return list<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}>
	 */
	public function filterExistingTemplateUserRelations(array $relations, int $userId): array
	{
		$memberIds = [];
		$representativeDocumentIds = [];
		foreach ($relations as $relation)
		{
			if ($relation['isRepresentative'])
			{
				$representativeDocumentIds[] = $relation['documentId'];
			}
			else
			{
				$memberIds[] = $relation['memberId'];
			}
		}

		return [
			...$this->getDirectTemplateUserRelationsByIds($memberIds, $userId),
			...$this->getRepresentativeTemplateUserRelationsByDocumentIds($representativeDocumentIds, $userId),
		];
	}

	/**
	 * @param list<int> $memberIds
	 * @return list<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}>
	 */
	private function getDirectTemplateUserRelationsByIds(array $memberIds, int $userId): array
	{
		if (empty($memberIds))
		{
			return [];
		}

		$rows = Internal\MemberTable::query()
			->setSelect([
				'MEMBER_ID' => 'ID',
				'DOCUMENT_ID',
				'TEMPLATE_ID' => 'DOCUMENT.TEMPLATE_ID',
			])
			->whereIn('ID', $memberIds)
			->where('ENTITY_TYPE', EntityType::USER)
			->where('ENTITY_ID', $userId)
			->whereIn(
				'ROLE',
				[
					$this->convertRoleToInt(Role::REVIEWER),
					$this->convertRoleToInt(Role::EDITOR),
				],
			)
			->where('DOCUMENT.TEMPLATE_ID', '>', 0)
			->where('DOCUMENT.ENTITY_TYPE', Type\Document\EntityType::SMART_B2E)
			->fetchAll()
		;

		return array_map($this->createTemplateUserRelation(...), $rows);
	}

	/**
	 * @param list<int> $documentIds
	 * @return list<array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}>
	 */
	private function getRepresentativeTemplateUserRelationsByDocumentIds(array $documentIds, int $userId): array
	{
		if (empty($documentIds))
		{
			return [];
		}

		$rows = Internal\MemberTable::query()
			->setSelect([
				'MEMBER_ID' => 'ID',
				'DOCUMENT_ID',
				'TEMPLATE_ID' => 'DOCUMENT.TEMPLATE_ID',
			])
			->whereIn('DOCUMENT_ID', $documentIds)
			->where('ENTITY_TYPE', EntityType::COMPANY)
			->where('ROLE', $this->convertRoleToInt(Role::ASSIGNEE))
			->where('DOCUMENT.REPRESENTATIVE_ID', $userId)
			->where('DOCUMENT.TEMPLATE_ID', '>', 0)
			->where('DOCUMENT.ENTITY_TYPE', Type\Document\EntityType::SMART_B2E)
			->fetchAll()
		;

		return array_map(
			fn(array $row): array => $this->createTemplateUserRelation($row, true),
			$rows,
		);
	}

	/**
	 * @param array{MEMBER_ID: int|string, DOCUMENT_ID: int|string, TEMPLATE_ID: int|string} $row
	 * @return array{templateId: int, documentId: int, memberId: int, isRepresentative: bool}
	 */
	private function createTemplateUserRelation(array $row, bool $isRepresentative = false): array
	{
		return [
			'templateId' => (int)$row['TEMPLATE_ID'],
			'documentId' => (int)$row['DOCUMENT_ID'],
			'memberId' => (int)$row['MEMBER_ID'],
			'isRepresentative' => $isRepresentative,
		];
	}

	private function prepareListB2eDocumentsByUserIdQuery(
		array $documentStatuses,
		array $memberStatuses,
		int $limit = 30,
	): Query
	{
		return Internal\MemberTable
			::query()
			->setSelect(['ID', 'DATE_SIGN', 'ROLE', 'DOCUMENT.TITLE', 'DOCUMENT.EXTERNAL_ID'])
			->where('DOCUMENT.ENTITY_TYPE', Type\Document\EntityType::SMART_B2E)
			->whereIn('SIGNED', $memberStatuses)
			->whereIn('DOCUMENT.STATUS', $documentStatuses)
			->setLimit($limit)
			->setOrder(['ID' => 'DESC'])
		;
	}

	private function prepareListB2eDocumentsByUserIdCollection(
		Query $query,
	): Item\Integration\SignMobile\MemberDocumentCollection {
		$memberDocuments = array_map(
			fn(Internal\Member $member) => new Item\Integration\SignMobile\MemberDocument(
				memberId: $member->getId(),
				memberRole: $this->convertIntToRole($member->getRole()),
				dateSigned: $member->getDateSign(),
				documentId: $member->getDocument()?->getId(),
				documentTitle: $member->getDocument()?->getTitle(),
				documentExternalId: $member->getDocument()?->getExternalId(),
			),
			$query->fetchCollection()->getAll(),
		);

		return new Item\Integration\SignMobile\MemberDocumentCollection(...$memberDocuments);
	}

	public function listB2eReviewDocumentsByUserId(int $userId, int $limit = 30): Item\Integration\SignMobile\MemberDocumentCollection
	{
		$query = $this->prepareListB2eDocumentsByUserIdQuery(
			[DocumentStatus::SIGNING],
			[MemberStatus::READY],
			$limit,
		);

		$query->where('ROLE', $this->convertRoleToInt(Role::REVIEWER))
			->where('ENTITY_ID', $userId)
			->where('ENTITY_TYPE', '=', EntityType::USER)
		;

		return $this->prepareListB2eDocumentsByUserIdCollection($query);
	}

	public function listB2eSigningDocumentsByUserId(int $userId, int $limit = 30): Item\Integration\SignMobile\MemberDocumentCollection
	{
		$query = $this->prepareListB2eDocumentsByUserIdQuery(
			[DocumentStatus::SIGNING],
			[MemberStatus::READY],
			$limit,
		);

		$filter = Query::filter()
			->logic('or')
			->where(
				Query::filter()
					->logic('and')
					->where('ENTITY_TYPE', '=', EntityType::USER)
					->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
					->where('ENTITY_ID', $userId),
			)
			->where(
				Query::filter()
					->logic('and')
					->where('ENTITY_TYPE', '=', EntityType::COMPANY)
					->where('ROLE', '=', $this->convertRoleToInt(Role::ASSIGNEE))
					->where('DOCUMENT.REPRESENTATIVE_ID', '=', $userId),
			)
		;
		$query->where($filter);

		return $this->prepareListB2eDocumentsByUserIdCollection($query);
	}

	public function listB2eSignedDocumentsByUserId(int $userId, int $limit = 30): Item\Integration\SignMobile\MemberDocumentCollection
	{
		$query = $this->prepareListB2eDocumentsByUserIdQuery(
			[DocumentStatus::DONE],
			[MemberStatus::DONE],
			$limit,
		);

		$query->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->where('ENTITY_ID', $userId)
			->where('ENTITY_TYPE', '=', EntityType::USER)
		;

		return $this->prepareListB2eDocumentsByUserIdCollection($query);
	}

	public function listByDocumentIdWithRole(int $documentId, string $role, int $limit = 0, int $offset = 0): Item\MemberCollection
	{
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt($role))
			->setOrder(['ID' => 'ASC'])
		;

		if ($limit)
		{
			$models->setLimit($limit);
		}

		if ($offset)
		{
			$models->setOffset($offset);
		}

		return $this->extractItemCollectionFromModelCollection($models->fetchCollection());
	}

	public function getByDocumentIdWithRole(int $documentId, string $role): ?Item\Member
	{
		return $this->listByDocumentIdWithRole($documentId, $role, 1)->getFirst();
	}

	public function isUserMemberOrInitiatorWithDoneStatus(int $userId): bool
	{
		$filter =
			(new ConditionTree())
				->where('SIGNED', MemberStatus::DONE)
				->where('DOCUMENT.ENTITY_TYPE', \Bitrix\Sign\Type\Document\EntityType::SMART_B2E)
				->where(
					(new ConditionTree())
						->logic(ConditionTree::LOGIC_OR)
						->where(
							(new ConditionTree())
								->where('ENTITY_TYPE', EntityType::USER)
								->where('ENTITY_ID', $userId)
						)
						->where(
							(new ConditionTree())
								->logic(ConditionTree::LOGIC_OR)
								->where('DOCUMENT.CREATED_BY_ID', $userId)
								->where('DOCUMENT.REPRESENTATIVE_ID', $userId)
						)
				)
		;

		$query = Internal\MemberTable
			::query()
			->setSelect(['ID'])
			->where($filter)
		;

		return (int)$query->queryCountTotal() > 0;
	}

	public function isDocumentHasReviewer(int $documentId): bool
	{
		return $this->isDocumentHasMemberWithRoles($documentId, [Role::REVIEWER]);
	}

	public function isDocumentHasEditor(int $documentId): bool
	{
		return $this->isDocumentHasMemberWithRoles($documentId, [Role::EDITOR]);
	}

	public function isDocumentHasWaitReviewer(int $documentId): bool
	{
		$model = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt(Role::REVIEWER))
			->where('SIGNED', MemberStatus::WAIT)
			->setLimit(1)
		;

		return !!$model->fetch();
	}

	/**
	 * Whether the parties of the document ask for the filling before the approval. The reordered
	 * stages of a company document write exactly that queue, and a document written that way keeps
	 * it whatever the feature is switched to afterwards.
	 *
	 * Grouped in the database: a document of a synced department holds thousands of members.
	 */
	public function isEditorPartyBeforeReviewerParty(int $documentId): bool
	{
		$rows = Internal\MemberTable::query()
			->setSelect([
				'ROLE',
				new Main\Entity\ExpressionField('FIRST_PARTY', 'MIN(%s)', 'PART'),
			])
			->where('DOCUMENT_ID', $documentId)
			->whereIn('ROLE', $this->getValidationRoleIds())
			->setGroup(['ROLE'])
			->fetchAll()
		;

		$firstParties = [];
		foreach ($rows as $row)
		{
			$firstParties[$this->convertIntToRole((int)$row['ROLE'])] = (int)$row['FIRST_PARTY'];
		}

		$editorParty = $firstParties[Role::EDITOR] ?? null;
		$reviewerParty = $firstParties[Role::REVIEWER] ?? null;

		return $editorParty !== null && $reviewerParty !== null && $editorParty < $reviewerParty;
	}

	/**
	 * The member the validation stage of the document ends with: the one holding the last party of the
	 * filling and the approval. A document carrying no such member is named nothing.
	 *
	 * The parties are decided when the composition is written and frozen once the document is sent, so
	 * the answer does not change while the document runs. A caller that has to act once per run can
	 * bind the action to this member instead of watching the stage for the moment it ends.
	 *
	 * A party the composition would have to number twice is settled by the lower id, which keeps the
	 * answer the same for every caller. Every party but the last one holds a single member, so the
	 * composition never asks that question.
	 */
	public function getLastValidationMemberId(int $documentId): ?int
	{
		$row = Internal\MemberTable::query()
			->addSelect('ID')
			->where('DOCUMENT_ID', $documentId)
			->whereIn('ROLE', $this->getValidationRoleIds())
			->addOrder('PART', 'DESC')
			->addOrder('ID', 'ASC')
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : (int)$row['ID'];
	}

	/**
	 * @return list<int>
	 */
	private function getValidationRoleIds(): array
	{
		return [
			$this->convertRoleToInt(Role::EDITOR),
			$this->convertRoleToInt(Role::REVIEWER),
		];
	}

	public function isDocumentHasMemberWithRoles(int $documentId, array $roles): bool
	{
		$roleIds = array_map(fn(string $role) => $this->convertRoleToInt($role), $roles);
		$model = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->whereIn('ROLE', $roleIds)
			->setLimit(1)
		;

		return !!$model->fetch();
	}

	/**
	 * @param array<int> $documentIds
	 * @param array<string> $roles
	 * @param bool $loadEntityNames Load names from user and CRM entities. With `false` the members come back
	 *   partially hydrated: `name` and `companyName` stay `null` even when the entity has a name, because
	 *   the per-member reads of the user and CRM entities are skipped. Callers that display those properties
	 *   must not pass `false`.
	 * @return Item\MemberCollection
	 */
	public function listByDocumentIdListAndRoles(
		array $documentIds,
		array $roles,
		bool $loadEntityNames = true,
	): Item\MemberCollection
	{
		if (empty($documentIds) || empty($roles))
		{
			return new Item\MemberCollection();
		}

		$roleIds = array_map(fn(string $role) => $this->convertRoleToInt($role), $roles);
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->whereIn('DOCUMENT_ID', $documentIds)
			->whereIn('ROLE', $roleIds)
		;

		return $this->extractItemCollectionFromModelCollection($models->fetchCollection(), $loadEntityNames);
	}

	public function getByDocumentIdWithParty(int $documentId, int $party, string $entityType, int $entityId): Item\Member
	{
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->where('ENTITY_TYPE', $entityType)
			->where('ENTITY_ID', $entityId)
			->where('PART', $party)
			->setLimit(1)
		;

		return $this->extractItemFromModel($models->fetchObject());
	}

	public function countByDocumentIdAndParty(int $documentId, int $party): int
	{
		return Internal\MemberTable::getCount(
			[
				'=DOCUMENT_ID' => $documentId,
				'=PART' => $party,
			],
		);
	}

	/**
	 * Whether the party holds more than one member, without counting the rest of it.
	 *
	 * The index covers DOCUMENT_ID alone, so counting a party reads every member row of the document;
	 * a mass mailing is exactly the case where there are many of them, and two rows already answer.
	 */
	public function hasSeveralMembersInParty(int $documentId, int $party): bool
	{
		$rows = Internal\MemberTable
			::query()
			->addSelect('ID')
			->where('DOCUMENT_ID', $documentId)
			->where('PART', $party)
			->setLimit(2)
			->fetchAll()
		;

		return count($rows) > 1;
	}

	public function listByDocumentIdExcludeParty(int $documentId, int $party): Item\MemberCollection
	{
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->whereNot('PART', $party)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	public function listByDocumentIdExcludeRole(int $documentId, string $role): Item\MemberCollection
	{
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->whereNot('ROLE', $this->convertRoleToInt($role))
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	/**
	 * @param int $documentId
	 * @param Role::* $roles
	 *
	 * @return \Bitrix\Sign\Item\MemberCollection
	 */
	public function listByDocumentIdExcludeRoles(int $documentId, string... $roles): Item\MemberCollection
	{
		$roleIds = array_map($this->convertRoleToInt(...), $roles);
		$models = Internal\MemberTable::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->whereNotIn('ROLE', $roleIds)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	public function listByDocumentId(int $documentId): Item\MemberCollection
	{
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	public function getHavingOldestReminderByDocumentId(int $documentId): ?Item\Member
	{
		$memberEntity = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->setOrder(['REMINDER_START_DATE' => 'DESC'])
			->setLimit(1)
			->fetchObject()
		;

		return $memberEntity
			? $this->extractItemFromModel($memberEntity)
			: null
		;
	}

	public function getByUid(string $uid): ?Item\Member
	{
		$memberEntity = Internal\MemberTable::query()
			->addSelect('*')
			->where('UID', $uid)
			->setCacheTtl(3600)
			->fetchObject();

		return $memberEntity
			? $this->extractItemFromModel($memberEntity)
			: null
		;
	}

	private function generateUniqueUid(): string
	{
		do
		{
			$uid = $this->generateUid();
		}
		while ($this->isMemberWithUidExist($uid));

		return $uid;
	}

	/**
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 */
	private function isMemberWithUidExist(string $uid): bool
	{
		$fetchData = Internal\MemberTable
			::query()
			->addSelect('ID')
			->where('UID', $uid)
			->setLimit(1)
			->fetch()
		;

		return $fetchData !== false;
	}

	private function generateUid(): string
	{
		return Random::getStringByAlphabet(32, Random::ALPHABET_ALPHALOWER | Random::ALPHABET_NUM);
	}

	public function update(Item\Member $item): UpdateResult
	{
		if (!$item->id)
		{
			return (new UpdateResult())->addError(new Error('Document not found'));
		}

		$member = Internal\MemberTable::getById($item->id)
			->fetchObject();

		$binder = new MemberBinder($item, $member);
		$binder->setChangedItemPropertiesToModel();

		$member->setDateModify(new DateTime());

		$result = $member->save();
		if ($result->isSuccess())
		{
			$item->initOriginal();
		}

		return $result;
	}

	public function completeValidationMember(int $memberId, int $documentId, bool $local): Main\Result
	{
		return $this->writeValidationMember(
			$memberId,
			$documentId,
			function (Internal\Member $model) use ($local): Main\Result {
				$before = $this->extractItemFromModel($model, loadEntityNames: false);
				$applicable = in_array($before->role, [Role::REVIEWER, Role::EDITOR], true);
				$changed = $applicable && $before->status !== MemberStatus::DONE;
				if ($changed)
				{
					$now = new DateTime();
					$model->setSigned(MemberStatus::DONE);
					$model->setDateStatusChanged($now);
					$model->setDateModify($now);
					if ($model->getDateSign() === null)
					{
						$model->setDateSign($now);
					}
					if ($local)
					{
						$meta = $model->getMeta() ?? [];
						$meta[self::VALIDATION_CRM_DISPATCH_META_KEY] = self::VALIDATION_CRM_DISPATCH_PENDING;
						$model->setMeta($meta);
					}
					$saveResult = $model->save();
					if (!$saveResult->isSuccess())
					{
						return (new Main\Result())->addErrors($saveResult->getErrors());
					}
				}

				return (new Main\Result())->setData([
					'applicable' => $applicable,
					'before' => $before,
					'after' => $this->extractItemFromModel($model, loadEntityNames: false),
					'changed' => $changed,
				]);
			},
		);
	}

	/** @internal */
	public function getValidationCrmDispatchState(int $memberId, int $documentId): Main\Result
	{
		try
		{
			$model = Internal\MemberTable::query()
				->setSelect(['*'])
				->where('ID', $memberId)
				->where('DOCUMENT_ID', $documentId)
				->setCacheTtl(0)
				->fetchObject()
			;
			if ($model === null)
			{
				return (new Main\Result())->addError(new Error('Validation member not found in document.'));
			}
			$validators = Internal\MemberTable::query()
				->setSelect(['*'])
				->where('DOCUMENT_ID', $documentId)
				->whereIn('ROLE', [Role::toIntMap()[Role::REVIEWER], Role::toIntMap()[Role::EDITOR]])
				->setOrder(['PART' => 'DESC', 'ID' => 'ASC'])
				->setCacheTtl(0)
				->fetchCollection()
			;
			$marker = $model->getMeta()[self::VALIDATION_CRM_DISPATCH_META_KEY] ?? 'absent';

			return (new Main\Result())->setData([
				'member' => $this->extractItemFromModel($model, loadEntityNames: false),
				'marker' => in_array($marker,
					[self::VALIDATION_CRM_DISPATCH_PENDING, self::VALIDATION_CRM_DISPATCH_HANDLED], true)
					? $marker : 'absent',
				'validators' => $this->extractItemCollectionFromModelCollection($validators, loadEntityNames: false),
			]);
		}
		catch (\Throwable)
		{
			return (new Main\Result())->addError(new Error('Validation CRM state read failed.'));
		}
	}

	public function hasPendingValidationCrmDispatch(int $memberId, int $documentId): bool
	{
		$model = Internal\MemberTable::query()->setSelect(['*'])->where('ID', $memberId)->fetchObject();
		if ($model === null || $model->getDocumentId() !== $documentId)
		{
			return false;
		}
		$member = $this->extractItemFromModel($model, loadEntityNames: false);

		return in_array($member->role, [Role::REVIEWER, Role::EDITOR], true)
			&& ($model->getMeta()[self::VALIDATION_CRM_DISPATCH_META_KEY] ?? null)
				=== self::VALIDATION_CRM_DISPATCH_PENDING;
	}

	public function markValidationCrmDispatchHandled(int $memberId, int $documentId): Main\Result
	{
		return $this->writeValidationMember(
			$memberId,
			$documentId,
			function (Internal\Member $model): Main\Result {
				$member = $this->extractItemFromModel($model, loadEntityNames: false);
				if (!in_array($member->role, [Role::REVIEWER, Role::EDITOR], true))
				{
					return (new Main\Result())->addError(new Error('Wrong validation member role.'));
				}
				$meta = $model->getMeta() ?? [];
				if (($meta[self::VALIDATION_CRM_DISPATCH_META_KEY] ?? null) === self::VALIDATION_CRM_DISPATCH_HANDLED)
				{
					return new Main\Result();
				}
				$meta[self::VALIDATION_CRM_DISPATCH_META_KEY] = self::VALIDATION_CRM_DISPATCH_HANDLED;
				$model->setMeta($meta);

				return $model->save();
			},
		);
	}

	private function writeValidationMember(int $memberId, int $documentId, \Closure $write): Main\Result
	{
		$pool = Main\Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = null;
		$transactionStarted = false;
		try
		{
			$connection = Internal\MemberTable::getEntity()->getConnection();
			$connection->startTransaction();
			$transactionStarted = true;
			$this->lockValidationMember($connection, $memberId);
			$model = Internal\MemberTable::query()->setSelect(['*'])->where('ID', $memberId)
				->where('DOCUMENT_ID', $documentId)->setCacheTtl(0)->fetchObject();
			$result = $model === null || $model->getDocumentId() !== $documentId
				? (new Main\Result())->addError(new Error('Validation member not found in document.'))
				: $write($model);
			// Main consumes the nesting level before executing commit or rollback SQL.
			$transactionStarted = false;
			if ($result->isSuccess())
			{
				$connection->commitTransaction();
			}
			else
			{
				$this->rollbackAnnulmentTransaction($connection);
			}

			return $result;
		}
		catch (\Throwable $error)
		{
			if ($transactionStarted)
			{
				$this->rollbackAnnulmentTransaction($connection);
			}

			return (new Main\Result())->addError(new Error($error->getMessage()));
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function lockValidationMember(Main\DB\Connection $connection, int $memberId): void
	{
		$sqlHelper = $connection->getSqlHelper();
		$where = Query::buildFilterSql(
			Internal\MemberTable::getEntity(),
			(new ConditionTree())->where('ID', $memberId),
		);
		$connection->query(
			'SELECT ' . $sqlHelper->quote('ID')
			. ' FROM ' . $sqlHelper->quote(Internal\MemberTable::getTableName())
			. " WHERE {$where} FOR UPDATE",
		)->fetch();
	}

	public function getByDocumentAndPartAndEntityTypeAndEntityId(
		int $documentId,
		int $party,
		string $entityType,
		int|string $entityId,
	): ?Item\Member
	{
		$model = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->where('ENTITY_TYPE', $entityType)
			->where('ENTITY_ID', $entityId)
			->where('PART', $party)
			->setLimit(1)
			->exec()
			->fetchObject()
		;

		return $this->extractItemFromModel($model);
	}

	public function listWithFilter(ConditionTree $filter, int $limit = 0): Item\MemberCollection
	{
		$query = Internal\MemberTable::query()
			->addSelect('*')
			->where($filter)
		;
		if ($limit > 0)
		{
			$query->setLimit($limit);
		}

		/** @var Internal\MemberCollection $models */
		$models = $query->fetchCollection();

		return $models === null
			? new Item\MemberCollection()
			: $this->extractItemCollectionFromModelCollection($models)
		;
	}

	/**
	 * @param int $documentId
	 * @param array<MemberStatus::*> $memberStatuses
	 * @param ConditionTree $filter
	 * @param int $limit
	 *
	 */
	public function listByDocumentIdAndMemberStatusesAndCustomFilter(
		int $documentId,
		array $memberStatuses,
		ConditionTree $filter,
		int $limit = 0,
	): Item\MemberCollection
	{
		if ($documentId <= 0)
		{
			return new Item\MemberCollection();
		}

		$query = Internal\MemberTable::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->whereIn('SIGNED', $memberStatuses)
			->where($filter)
		;
		if ($limit > 0)
		{
			$query->setLimit($limit);
		}

		$models = $query->fetchCollection();

		return $this->extractItemCollectionFromModelCollection($models);
	}

	public function listSignersByUserIdIsDone(
		int $entityId,
		ConditionTree $filter,
		int $limit = 20,
		int $offset = 0,
	): Item\MemberCollection
	{
		$query = Internal\MemberTable
			::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE', EntityType::USER)
			->where('ENTITY_ID', $entityId)
			->whereIn('SIGNED', MemberStatus::DONE)
			->whereIn('DOCUMENT.STATUS', DocumentStatus::getEnding())
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->where($filter)
			->setLimit($limit)
			->setOffset($offset)
			->setOrder(['ID' => 'desc'])
		;

		$this->updateQueryByRefFields($filter, $query);

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection())
			->setQueryTotal((int)$query->queryCountTotal());
	}

	public function listSignersByUserIdIsNotWait(
		int $entityId,
		ConditionTree $filter,
		int $limit = 20,
		int $offset = 0,
	): Item\MemberCollection
	{
		$query = Internal\MemberTable
			::query()
			->setSelect(['*'])
			->where('ENTITY_TYPE', EntityType::USER)
			->where('ENTITY_ID', $entityId)
			->whereNot('SIGNED', MemberStatus::WAIT)
			->where($filter)
			->setLimit($limit)
			->setOffset($offset)
			->setOrder(['ID' => 'desc'])
		;

		$this->updateQueryByRefFields($filter, $query);

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection())
			->setQueryTotal((int)$query->queryCountTotal());
	}

	public function getAssigneeByDocumentId(int $documentId): ?Item\Member
	{
		$model = Internal\MemberTable
			::query()
			->setSelect(['*'])
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt(Role::ASSIGNEE))
			->setLimit(1)
			->fetchObject()
		;

		return $model === null ? null : $this->extractItemFromModel($model);
	}

	public function getByPartyAndDocumentId(int $documentId, int $part): ?Item\Member
	{
		$model = Internal\MemberTable
			::query()
			->setSelect(['*'])
			->where('DOCUMENT_ID', $documentId)
			->where('PART', $part)
			->setLimit(1)
			->fetchObject()
		;

		if ($model !== null)
		{
			return $this->extractItemFromModel($model);
		}

		return null;
	}

	/**
	 * @param array<Role::*, int> $roleRelevance
	 * @param array<MemberStatus::*, int> $statusRelevance
	 * @param bool $orderByParty puts the stored party number ahead of the role relevance, so the members
	 *   come back in the queue order of the document and the relevance only orders one party.
	 */
	public function listB2eMemberByDocumentId(
		int $documentId,
		?ConditionTree $filter,
		array $roleRelevance = [],
		array $statusRelevance = [],
		int $limit = 10,
		?int $offset = null,
		bool $orderByParty = false,
	): Item\MemberCollection
	{
		$roleRelevance = array_flip(
			array_map(
				static fn(string $value): int => Role::convertRoleToInt($value),
				array_flip($roleRelevance),
			),
		);

		$query = Internal\MemberTable
			::query()
			->setSelect(['*'])
			->where('DOCUMENT_ID', $documentId)
			->setLimit($limit)
		;
		if ($offset !== null)
		{
			$query->setOffset($offset);
		}
		if ($orderByParty)
		{
			$query->addOrder('PART');
		}
		if (!empty($roleRelevance))
		{
			$query
				->addSelect(new Main\Entity\ExpressionField(
					'ROLE_RELEVANCE',
					$this->getExpressionByRelevance($roleRelevance),
					'ROLE',
					),
				)
				->addOrder('ROLE_RELEVANCE')
			;
		}
		if (!empty($statusRelevance))
		{
			$query
				->addSelect(
				new Main\Entity\ExpressionField(
					'SIGNED_RELEVANCE',
					$this->getExpressionByRelevance($statusRelevance),
					'SIGNED',
					),
				)
				->addOrder('SIGNED_RELEVANCE')
			;
		}
		$query->addOrder('ID');

		if ($filter !== null)
		{
			$query->where($filter);
			$this->updateQueryByRefFields($filter, $query);
		}

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection());
	}

	/**
	 * @return array{REFUSED_MEMBERS_COUNTER: int, SUCCESS_MEMBERS_COUNTER: int, READY_MEMBERS_COUNTER: int}
	 */
	public function getMembersCountersByDocument(Item\Document $document, ?ConditionTree $filter): array
	{
		$signer = Role::convertRoleToInt(Role::SIGNER);
		$refusedMemberStatus = MemberStatus::REFUSED;
		$doneMemberStatus = MemberStatus::DONE;
		$readyMemberStatus = MemberStatus::READY;
		$processingMemberStatus = MemberStatus::PROCESSING;
		$stoppableReadyMemberStatus = MemberStatus::STOPPABLE_READY;

		$query = Internal\MemberTable
			::query()
			->setSelect([
				new Main\Entity\ExpressionField(
					'REFUSED_MEMBERS_COUNTER',
					"SUM(CASE 
					WHEN %s = '$signer' 
					AND %s = '$refusedMemberStatus' 
					THEN 1 ELSE 0 END)",
					['ROLE', 'SIGNED'],
				),
				new Main\Entity\ExpressionField(
					'SUCCESS_MEMBERS_COUNTER',
					"SUM(CASE 
					WHEN %s = '$signer' 
					AND %s = '$doneMemberStatus' 
					THEN 1 ELSE 0 END)",
					['ROLE', 'SIGNED'],
				),
			])
			->where('DOCUMENT_ID', $document->id)
		;

		if ($document->status === DocumentStatus::STOPPED)
		{
			$query->addSelect(
				new Main\Entity\ExpressionField(
					'READY_MEMBERS_COUNTER',
					"SUM(CASE 
					WHEN %s = '$signer' 
					AND (%s = '$readyMemberStatus' 
					OR %s = '$processingMemberStatus') 
					THEN 1 ELSE 0 END)",
					['ROLE', 'SIGNED', 'SIGNED'],
				),
			);
		}
		else
		{
			$query->addSelect(
				new Main\Entity\ExpressionField(
					'READY_MEMBERS_COUNTER',
					"SUM(CASE 
					WHEN %s = '$signer' 
					AND (%s = '$readyMemberStatus' 
					OR %s = '$stoppableReadyMemberStatus' 
					OR %s = '$processingMemberStatus') 
					THEN 1 ELSE 0 END)",
					['ROLE', 'SIGNED', 'SIGNED', 'SIGNED'],
				),
			);
		}

		if ($filter?->hasConditions())
		{
			$query->where($filter);
			$this->updateQueryByRefFields($filter, $query);
		}

		if ($membersCounters = $query->fetch())
		{
			return array_map(static fn (string|null $value): int => (int)$value, $membersCounters);
		}
		else
		{
			return [
				'REFUSED_MEMBERS_COUNTER' => 0,
				'SUCCESS_MEMBERS_COUNTER' => 0,
				'READY_MEMBERS_COUNTER' => 0,
			];
		}
	}

	/**
	 * Filter node of the company safe annulment status (NORMATIVE ALG-01). The mark of the row shown
	 * is not always the mark of the signing that row speaks about: the company side row of an employee
	 * initiated document is asked of the document instead - has it an annulled finished signing - since
	 * the signer's own row is excluded from the safe listing. Every other row keeps answering with its
	 * own column. The same substitution class drives the row mark, see Service\B2e\AnnulmentTargetService.
	 *
	 * Existence instead of a join on purpose: a join would multiply the rows of a document with several
	 * finished signers and break every safe counter. The two modes stay exact complements - hence the
	 * explicit null members for the nullable initiator type and role columns - so the row counts of
	 * "annulled" and "signed" still add up to the count of "all".
	 *
	 * Existence condition and not an expression one: walking a filter, the query registers the field of
	 * an expression condition as a runtime field of its own and rewrites the definition inside that very
	 * filter with the field's name (Main\ORM\Query\Query::setFilterHandlerChains()), which leaves the
	 * filter usable once, while the safe grid hands one built filter to a count query and to a listing
	 * query in a row. An existence condition carries no column name and stays reusable, at the price of
	 * the correlation - see getAnnulledSignerSubQuery().
	 */
	public function buildSafeAnnulmentCondition(bool $annulled): ConditionTree
	{
		$employeeInitiated = Type\Document\InitiatedByType::EMPLOYEE->toInt();
		$assignee = $this->convertRoleToInt(Role::ASSIGNEE);
		$subQuery = $this->getAnnulledSignerSubQuery();

		$substituted = Query::filter()
			->where('DOCUMENT.INITIATED_BY_TYPE', $employeeInitiated)
			->where('ROLE', $assignee)
		;
		if ($annulled)
		{
			$substituted->whereExists($subQuery);
		}
		else
		{
			$substituted->whereNotExists($subQuery);
		}

		return Query::filter()
			->logic('or')
			->where($substituted)
			->where(Query::filter()
				->where(Query::filter()
					->logic('or')
					->where('DOCUMENT.INITIATED_BY_TYPE', '!=', $employeeInitiated)
					->whereNull('DOCUMENT.INITIATED_BY_TYPE')
					->where('ROLE', '!=', $assignee)
					->whereNull('ROLE')
				)
				->where('ANNULLED', $annulled)
			)
		;
	}

	/**
	 * Finished annulled signing of the same document. An existence condition gets no column
	 * substitution from the query - see buildSafeAnnulmentCondition() - so the correlation is written
	 * out here as a reference to the column of the outer table. The alias of that table is read off the
	 * member entity instead of spelled out, so it follows the base query of the safe.
	 */
	private function getAnnulledSignerSubQuery(): Query
	{
		return Internal\MemberTable::query()
			->setCustomBaseTableAlias('sign_internal_member_annulled_signer')
			->where('DOCUMENT_ID', new Main\DB\SqlExpression($this->getSafeOuterDocumentIdSql()))
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->where('SIGNED', MemberStatus::DONE)
			->where('ANNULLED', true)
			->setLimit(1)
		;
	}

	/**
	 * Quoted `<outer alias>.DOCUMENT_ID` of the safe base query. Identifiers go through the sqlHelper of
	 * the member connection, part by part: quote() of the postgres helper takes the whole argument for a
	 * single identifier and would not split a dotted one.
	 */
	private function getSafeOuterDocumentIdSql(): string
	{
		$sqlHelper = Internal\MemberTable::getEntity()->getConnection()->getSqlHelper();

		return $sqlHelper->quote(Internal\MemberTable::query()->getInitAlias())
			. '.'
			. $sqlHelper->quote('DOCUMENT_ID')
		;
	}

	/**
	 * Base query for the company safe ("My Safe") member listing: only members with a signed result
	 * file, whose document has reached an ending status, minus the signer's own result file when the
	 * signer initiated the flow. Shared by the list/count/folder-id readers so the safe grid, the
	 * pagination total and the folder gate all see the same member set.
	 */
	private function buildB2eMySafeBaseQuery(ConditionTree $filter): Query
	{
		$query = Internal\MemberTable
			::query()
			// load members with result file only
			->registerRuntimeField("",
				(new Main\ORM\Fields\Relations\Reference(
					"RESULT_FILE",
					Internal\FileTable::getEntity(),
					Main\ORM\Query\Join::on("this.ID", 'ref.ENTITY_ID')
						->where('ref.ENTITY_TYPE_ID', \Bitrix\Sign\Type\EntityType::MEMBER)
						->where('ref.CODE', Type\EntityFileCode::SIGNED)
					,
					[
						'join_type' => Main\ORM\Query\Join::TYPE_INNER,
					],
				)),
			)
			->registerRuntimeField(
				'SAFE_FOLDER_RELATION',
				new Main\ORM\Fields\Relations\Reference(
					'SAFE_FOLDER_RELATION',
					Internal\Document\Folder\DocumentFolderRelationTable::getEntity(),
					Join::on('this.ID', 'ref.ENTITY_ID')->where(
						'ref.ENTITY_TYPE',
						Type\Document\Folder\EntityType::MEMBER->value,
					),
					['join_type' => Join::TYPE_LEFT],
				),
			)
			->registerRuntimeField(
				'FOLDER_ID',
				new Main\ORM\Fields\ExpressionField(
					'FOLDER_ID',
					'%s',
					['SAFE_FOLDER_RELATION.PARENT_ID'],
				),
			)
			->registerRuntimeField(
				'SAFE_FOLDER',
				new Main\ORM\Fields\Relations\Reference(
					'SAFE_FOLDER',
					Internal\Document\DocumentFolderTable::getEntity(),
					Join::on('this.SAFE_FOLDER_RELATION.PARENT_ID', 'ref.ID'),
					['join_type' => Join::TYPE_LEFT],
				),
			)
			->whereIn('DOCUMENT.STATUS', DocumentStatus::getEnding())
			->where($filter)
			// exclude signer result file if the signer has initiated the process
			->whereNot(Query::filter()
				->where('DOCUMENT.INITIATED_BY_TYPE', Type\Document\InitiatedByType::EMPLOYEE->toInt())
				->where('ROLE', Role::convertRoleToInt(Role::SIGNER))
			)
		;

		$this->updateQueryByRefFields($filter, $query);

		return $query;
	}

	public function listB2eMembersWithResultFilesForMySafe(
		ConditionTree $filter,
		int $limit = 20,
		int $offset = 0,
		?Type\MySafeSortField $orderField = null,
		Main\DB\Order $orderDirection = Main\DB\Order::Desc,
		bool $countTotal = true,
	): Item\MemberCollection
	{
		$query = $this->buildB2eMySafeBaseQuery($filter)
			->setSelect(['*', 'FOLDER_ID'])
			->setLimit($limit)
			->setOffset($offset)
		;

		$this->applyMySafeOrder($query, $orderField, $orderDirection);

		$collection = $this->extractItemCollectionFromModelCollection($query->fetchCollection());
		$this->fillSafeFolderIds($collection);

		return $countTotal ? $collection->setQueryTotal((int)$query->queryCountTotal()) : $collection;
	}

	public function listB2eMembersWithResultFilesForMySafeExport(
		ConditionTree $filter,
		int $limit,
	): Item\MemberCollection
	{
		if ($limit < 1)
		{
			return new Item\MemberCollection();
		}

		$query = $this->buildB2eMySafeBaseQuery($filter)
			->setSelect([
				'ID',
				'DOCUMENT_ID',
				'PART',
				'SIGNED',
				'ENTITY_TYPE',
				'ENTITY_ID',
				'DATE_SIGN',
				'ROLE',
				'FOLDER_ID',
				'ANNULLED',
			])
			->setLimit($limit)
		;
		$this->applyMySafeOrder($query, null, Main\DB\Order::Desc);

		$members = [];
		foreach ($query->fetchAll() as $row)
		{
			$party = (int)$row['PART'];
			$role = $row['ROLE'] === null
				? \Bitrix\Sign\Compatibility\Role::createByParty($party)
				: $this->convertIntToRole((int)$row['ROLE'])
			;
			$folderId = (int)$row['FOLDER_ID'];

			$members[] = new Item\Member(
				documentId: (int)$row['DOCUMENT_ID'],
				party: $party,
				id: (int)$row['ID'],
				status: (string)$row['SIGNED'],
				dateSigned: $row['DATE_SIGN'],
				entityType: $row['ENTITY_TYPE'],
				entityId: $row['ENTITY_ID'] === null ? null : (int)$row['ENTITY_ID'],
				role: $role,
				folderId: $folderId > 0 ? $folderId : null,
				annulled: (bool)$row['ANNULLED'],
			);
		}

		return new Item\MemberCollection(...$members);
	}

	private function fillSafeFolderIds(Item\MemberCollection $members): void
	{
		$memberIds = array_values(array_filter(
			$members->getIds(),
			static fn(?int $id): bool => $id !== null && $id > 0,
		));
		if ($memberIds === [])
		{
			return;
		}

		$folderIdsByMemberId = [];
		$rows = Internal\Document\Folder\DocumentFolderRelationTable::query()
			->setSelect(['ENTITY_ID', 'PARENT_ID'])
			->whereIn('ENTITY_ID', $memberIds)
			->where('ENTITY_TYPE', Type\Document\Folder\EntityType::MEMBER->value)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$parentId = (int)$row['PARENT_ID'];
			$folderIdsByMemberId[(int)$row['ENTITY_ID']] = $parentId > 0 ? $parentId : null;
		}

		foreach ($members as $member)
		{
			$member->folderId = $folderIdsByMemberId[$member->id] ?? null;
		}
	}

	/**
	 * Total number of safe members matching the filter, ignoring limit/offset. Feeds the combined
	 * folder + member pagination total (P5.T1) without fetching the member rows.
	 */
	public function countB2eMembersForMySafe(ConditionTree $filter): int
	{
		return (int)$this->buildB2eMySafeBaseQuery($filter)->queryCountTotal();
	}

	public function countB2eMembersForMySafeUpTo(ConditionTree $filter, int $limit): int
	{
		if ($limit < 1)
		{
			return 0;
		}

		$limitedQuery = $this->buildB2eMySafeBaseQuery($filter)
			->setSelect(['ID'])
			->setLimit($limit)
		;
		$countQuery = new Query($limitedQuery);
		$countQuery->setSelect([
			new Main\ORM\Fields\ExpressionField(
				'TOTAL',
				'COUNT(%s)',
				['ID'],
			),
		]);

		return (int)($countQuery->fetch()['TOTAL'] ?? 0);
	}

	/**
	 * @return array{userIds:list<int>, total:?int, nextCursor:?int}
	 */
	public function listSafeFolderPeopleIds(
		int $folderId,
		string $category,
		int $limit,
		?int $afterUserId,
	): array
	{
		$userExpression = match ($category)
		{
			'participants' => new Main\ORM\Fields\ExpressionField(
				'SAFE_USER_ID',
				"CASE WHEN %s = 'user' THEN %s WHEN %s = 'company' THEN %s ELSE NULL END",
				['ENTITY_TYPE', 'ENTITY_ID', 'ENTITY_TYPE', 'DOCUMENT.REPRESENTATIVE_ID'],
			),
			'representatives' => new Main\ORM\Fields\ExpressionField(
				'SAFE_USER_ID',
				'%s',
				['DOCUMENT.REPRESENTATIVE_ID'],
			),
			'senders' => new Main\ORM\Fields\ExpressionField(
				'SAFE_USER_ID',
				'%s',
				['DOCUMENT.CREATED_BY_ID'],
			),
			default => null,
		};
		if ($folderId <= 0 || $userExpression === null)
		{
			return ['userIds' => [], 'total' => 0, 'nextCursor' => null];
		}

		$query = $this->buildB2eMySafeBaseQuery(
			Query::filter()->where(self::SAFE_FOLDER_PARENT_COLUMN, $folderId),
		)
			->registerRuntimeField('SAFE_USER_ID', $userExpression)
			->setSelect(['SAFE_USER_ID'])
			->where('SAFE_USER_ID', '>', 0)
			->setGroup(['SAFE_USER_ID'])
			->setOrder(['SAFE_USER_ID' => 'ASC'])
		;
		$afterUserId = $afterUserId !== null && $afterUserId > 0 ? $afterUserId : null;
		$total = $afterUserId === null ? (int)$query->queryCountTotal() : null;
		if ($afterUserId !== null)
		{
			$query->where('SAFE_USER_ID', '>', $afterUserId);
		}

		$limit = max(1, $limit);
		$rows = [];
		$dbResult = $query->setLimit($limit + 1)->exec();
		while ($row = $dbResult->fetch())
		{
			$rows[] = $row;
		}
		$hasNextPage = count($rows) > $limit;
		if ($hasNextPage)
		{
			array_pop($rows);
		}
		$userIds = array_map(static fn(array $row): int => (int)$row['SAFE_USER_ID'], $rows);
		$nextCursor = $hasNextPage && $userIds !== [] ? $userIds[array_key_last($userIds)] : null;

		return [
			'userIds' => $userIds,
			'total' => $total,
			'nextCursor' => $nextCursor,
		];
	}

	/**
	 * Returns exact people counters, bounded previews and company document candidates for folder rows
	 * using one streaming database pass. Company candidates are resolved later through the CRM factory.
	 *
	 * People counters stay exact, but the company document candidates per folder are capped at
	 * $companyDocumentScanLimit so that a single large folder cannot force an unbounded number of
	 * later CRM resolution rounds or accumulate an unbounded id list in memory.
	 *
	 * @param list<int> $folderIds
	 * @param int $peoplePreviewLimit
	 * @param int $companyDocumentScanLimit maximum company document candidates collected per folder
	 * @return array<int, array{
	 *     participantIds: list<int>,
	 *     participantCount: int,
	 *     representativeIds: list<int>,
	 *     representativeCount: int,
	 *     senderIds: list<int>,
	 *     senderCount: int,
	 *     roleCodes: list<string>,
	 *     companyDocumentIds: list<int>,
	 * }>
	 */
	public function getSafeFolderAggregates(
		array $folderIds,
		int $peoplePreviewLimit,
		int $companyDocumentScanLimit,
	): array
	{
		$companyDocumentScanLimit = max(1, $companyDocumentScanLimit);
		$folderIds = array_values(array_unique(array_filter(
			array_map('intval', $folderIds),
			static fn(int $folderId): bool => $folderId > 0,
		)));
		if ($folderIds === [])
		{
			return [];
		}

		$result = [];
		foreach ($folderIds as $folderId)
		{
			$result[$folderId] = [
				'participantIds' => [],
				'participantCount' => 0,
				'representativeIds' => [],
				'representativeCount' => 0,
				'senderIds' => [],
				'senderCount' => 0,
				'roleCodes' => [],
				'companyDocumentIds' => [],
			];
		}

		$query = $this->buildB2eMySafeBaseQuery(
			Query::filter()->whereIn(self::SAFE_FOLDER_PARENT_COLUMN, $folderIds),
		)
			->registerRuntimeField(
				'SAFE_FOLDER_ID',
				new Main\ORM\Fields\ExpressionField(
					'SAFE_FOLDER_ID',
					'%s',
					[self::SAFE_FOLDER_PARENT_COLUMN],
				),
			)
			->registerRuntimeField(
				'SAFE_PARTICIPANT_ID',
				new Main\ORM\Fields\ExpressionField(
					'SAFE_PARTICIPANT_ID',
					"CASE WHEN %s = 'user' THEN %s WHEN %s = 'company' THEN %s ELSE NULL END",
					['ENTITY_TYPE', 'ENTITY_ID', 'ENTITY_TYPE', 'DOCUMENT.REPRESENTATIVE_ID'],
				),
			)
			->registerRuntimeField(
				'SAFE_REPRESENTATIVE_ID',
				new Main\ORM\Fields\ExpressionField(
					'SAFE_REPRESENTATIVE_ID',
					'%s',
					['DOCUMENT.REPRESENTATIVE_ID'],
				),
			)
			->registerRuntimeField(
				'SAFE_SENDER_ID',
				new Main\ORM\Fields\ExpressionField(
					'SAFE_SENDER_ID',
					'%s',
					['DOCUMENT.CREATED_BY_ID'],
				),
			)
			->setSelect([
				'SAFE_FOLDER_ID',
				'SAFE_PARTICIPANT_ID',
				'SAFE_REPRESENTATIVE_ID',
				'SAFE_SENDER_ID',
				'ROLE',
				'DOCUMENT_ID',
			])
		;

		$valueSets = [];
		$dbResult = $query->exec();
		while ($row = $dbResult->fetch())
		{
			$folderId = (int)$row['SAFE_FOLDER_ID'];
			if (!isset($result[$folderId]))
			{
				continue;
			}

			foreach ([
				'participant' => (int)$row['SAFE_PARTICIPANT_ID'],
				'representative' => (int)$row['SAFE_REPRESENTATIVE_ID'],
				'sender' => (int)$row['SAFE_SENDER_ID'],
			] as $key => $value)
			{
				if ($value > 0)
				{
					$valueSets[$folderId][$key][$value] = true;
				}
			}

			$role = Role::tryFromInt((int)$row['ROLE']);
			if ($role !== null)
			{
				$valueSets[$folderId]['role'][$role] = true;
			}
			$documentId = (int)$row['DOCUMENT_ID'];
			if (
				$documentId > 0
				&& count($valueSets[$folderId]['document'] ?? []) < $companyDocumentScanLimit
			)
			{
				$valueSets[$folderId]['document'][$documentId] = true;
			}
		}

		$peoplePreviewLimit = max(1, $peoplePreviewLimit);
		foreach ($folderIds as $folderId)
		{
			foreach (['participant', 'representative', 'sender'] as $key)
			{
				$ids = array_map('intval', array_keys($valueSets[$folderId][$key] ?? []));
				sort($ids, SORT_NUMERIC);
				$result[$folderId]["{$key}Count"] = count($ids);
				$result[$folderId]["{$key}Ids"] = array_slice($ids, 0, $peoplePreviewLimit);
			}
			$result[$folderId]['roleCodes'] = array_keys($valueSets[$folderId]['role'] ?? []);
			$documentIds = array_map('intval', array_keys($valueSets[$folderId]['document'] ?? []));
			sort($documentIds, SORT_NUMERIC);
			$result[$folderId]['companyDocumentIds'] = $documentIds;
		}

		return $result;
	}

	/**
	 * Distinct folder ids that hold at least one safe member matching the filter (P5.T2 folder gate).
	 * The caller passes the user filter already narrowed to the accessible folders, so the result is
	 * a database count with no materialized folder id list.
	 */
	public function countFolderIdsWithMembersForMySafe(ConditionTree $filter): int
	{
		return (int)$this->buildFolderIdsWithMembersForMySafeQuery($filter)->queryCountTotal();
	}

	/**
	 * When title order is provided, the matching folder page is ordered by folder title before
	 * limit/offset, with the folder id as the deterministic tie-breaker. Without a title order the
	 * page defaults to newest-first (folder id descending), matching the safe grid default.
	 *
	 * @return list<int>
	 */
	public function listFolderIdsWithMembersForMySafe(
		ConditionTree $filter,
		int $limit,
		int $offset,
		?Main\DB\Order $titleOrder = null,
	): array
	{
		$query = $this->buildFolderIdsWithMembersForMySafeQuery($filter);
		if ($titleOrder !== null)
		{
			$query
				->setSelect([
					'FOLDER_ID',
					'FOLDER_TITLE' => 'SAFE_FOLDER.TITLE',
				])
				->setGroup([
					self::SAFE_FOLDER_PARENT_COLUMN,
					'SAFE_FOLDER.TITLE',
				])
				->setOrder([
					'SAFE_FOLDER.TITLE' => $titleOrder->value,
					'FOLDER_ID' => 'ASC',
				])
			;
		}
		else
		{
			$query->setOrder(['FOLDER_ID' => 'DESC']);
		}

		$query
			->setLimit(max(1, $limit))
			->setOffset(max(0, $offset))
		;

		$ids = [];
		$dbResult = $query->exec();
		while ($row = $dbResult->fetch())
		{
			$ids[] = (int)$row['FOLDER_ID'];
		}

		return $ids;
	}

	private function buildFolderIdsWithMembersForMySafeQuery(ConditionTree $filter): Query
	{
		return $this->buildB2eMySafeBaseQuery($filter)
			->setSelect(['FOLDER_ID'])
			->where(self::SAFE_FOLDER_PARENT_COLUMN, '>', 0)
			->setGroup([self::SAFE_FOLDER_PARENT_COLUMN])
		;
	}

	/**
	 * Applies a deterministic ORDER BY to the company safe grid query.
	 *
	 * This method has a side effect: when sorting by the signing date it also
	 * registers the DATE_SIGN_EMPTY runtime field on $query, so building the
	 * order and mutating the query are done together here rather than split
	 * across the caller.
	 *
	 * A null field keeps the historical default ('ID' => 'DESC') so existing
	 * callers (e.g. the REST wrapper) are unaffected. 'ID' => 'DESC' is always
	 * appended last as a stable pagination tie-breaker. When sorting by the
	 * signing date, empty dates are pushed to the end in both directions via a
	 * runtime CASE field that is used only in ORDER BY and never selected, so
	 * it cannot leak into queryCountTotal().
	 */
	private function applyMySafeOrder(
		Query $query,
		?Type\MySafeSortField $orderField,
		Main\DB\Order $orderDirection,
	): void
	{
		// fail-safe default: independent of the caller
		if ($orderField === null)
		{
			$query->setOrder(self::MY_SAFE_DEFAULT_ORDER);

			return;
		}

		if ($orderField === Type\MySafeSortField::DateSign)
		{
			$sqlHelper = Main\Application::getConnection()->getSqlHelper();
			// A real DateTime is bound through the SqlHelper so the comparison
			// is portable across MySQL/PostgreSQL.
			$emptyThreshold = new DateTime(self::MY_SAFE_EMPTY_SIGN_DATE_THRESHOLD, 'Y-m-d H:i:s');
			$thresholdSql = $sqlHelper->convertToDbDateTime($emptyThreshold);

			$query->registerRuntimeField(
				'DATE_SIGN_EMPTY',
				new Main\Entity\ExpressionField(
					'DATE_SIGN_EMPTY',
					"CASE WHEN %s IS NULL OR %s < {$thresholdSql} THEN 1 ELSE 0 END",
					['DATE_SIGN', 'DATE_SIGN'],
				),
			);

			$query->setOrder([
				'DATE_SIGN_EMPTY' => 'ASC',
				'DATE_SIGN' => $orderDirection->value,
				'ID' => 'DESC',
			]);

			return;
		}

		$query->setOrder([
			$orderField->value => $orderDirection->value,
			'ID' => 'DESC',
		]);
	}

	public function listByUids(array $uids): Item\MemberCollection
	{
		$models = Internal\MemberTable::query()
			->addSelect('*')
			->whereIn('UID', $uids)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	/**
	 * @param array<int> $ids
	 * @param bool $loadEntityNames Load names from user and CRM entities. With `false` the members come back
	 *   partially hydrated: `name` and `companyName` stay `null` even when the entity has a name, because
	 *   the per-member reads of the user and CRM entities are skipped. Callers that display those properties
	 *   must not pass `false`.
	 * @return Item\MemberCollection
	 */
	public function listByIds(array $ids, bool $loadEntityNames = true): Item\MemberCollection
	{
		$models = Internal\MemberTable::query()
			->addSelect('*')
			->whereIn('ID', $ids)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models, $loadEntityNames);
	}

	/**
	 * Returns ids of documents the given members belong to. Unlike listByUids() it does not
	 * hydrate member items, so no user and CRM lookups are made.
	 *
	 * @param array<string> $memberUids
	 * @return list<int>
	 */
	public function listDocumentIdsByMemberUids(array $memberUids): array
	{
		if (empty($memberUids))
		{
			return [];
		}

		$rows = Internal\MemberTable::query()
			->setSelect(['DOCUMENT_ID'])
			->whereIn('UID', $memberUids)
			->setDistinct()
			->fetchAll()
		;

		return $this->extractDocumentIds($rows);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<int>
	 */
	private function extractDocumentIds(array $rows): array
	{
		$documentIds = array_map('intval', array_column($rows, 'DOCUMENT_ID'));

		return array_values(array_filter($documentIds));
	}

	public function getById(int $id): ?Item\Member
	{
		$memberEntity = Internal\MemberTable::query()
			->addSelect('*')
			->where('ID', $id)
			->fetchObject();

		return $memberEntity
			? $this->extractItemFromModel($memberEntity)
			: null
		;
	}

	private static function getCrmContactName(int $entityId): ?string
	{
		return \Bitrix\Sign\Integration\CRM\Entity::getContactName($entityId);
	}

	private static function getCrmCompanyName(int $entityId): ?string
	{
		if (!Main\Loader::includeModule('crm'))
		{
			return null;
		}

		return \Bitrix\Crm\Service\Container::getInstance()->getCompanyBroker()->getTitle($entityId);
	}

	private static function getNameForUser(int $userId, ?Main\EO_User $userModel = null): ?string
	{
		if (empty($userId))
		{
			return null;
		}

		$userModel ??= Main\UserTable::getById($userId)->fetchObject();

		return $userModel
			? \CUser::FormatName(
				\Bitrix\Main\Context::getCurrent()->getCulture()->getNameFormat(),
				[
					'LOGIN' => '',
					'NAME' => $userModel->getName(),
					'LAST_NAME' => $userModel->getLastName(),
					'SECOND_NAME' => $userModel->getSecondName(),
				],
				false, false,
			)
			: null
		;
	}

	public function convertRoleToInt(string $role): int
	{
		return Role::convertRoleToInt($role);
	}

	private function convertIntToRole(int $roleNumber): string
	{
		return Role::convertIntToRole($roleNumber);
	}

	public function updateQueryByRefFields(ConditionTree $filter, Query $query): void
	{
		$conditions = array_filter(
			$filter->getConditions(),
			static fn($condition) => ($condition instanceof Condition),
		);
		if (
			!empty($conditions)
			&& !empty(
				array_filter(
					$conditions,
					static fn(Condition $condition) => self::isCompanyRefFieldColumn($condition->getColumn()),
				)
			)
		)
		{
			$query->registerRuntimeField(
				self::SIGN_DOCUMENT_LIST_QUERY_REF_FIELD_NAME_COMPANY,
				new ReferenceField(
					self::SIGN_DOCUMENT_LIST_QUERY_REF_FIELD_NAME_COMPANY,
					Internal\MemberTable::getEntity(),
					Join::on('ref.DOCUMENT_ID', 'this.DOCUMENT_ID'),
					['join_type' => Join::TYPE_INNER],
				),
			);
		}
	}

	/**
	 * Expression and exists conditions carry a field object or null instead of a column name
	 * (main/lib/ORM/Query/Filter/Condition.php), so the prefix check must not assume a string.
	 */
	private static function isCompanyRefFieldColumn(mixed $column): bool
	{
		return is_string($column)
			&& str_starts_with($column, self::SIGN_DOCUMENT_LIST_QUERY_REF_FIELD_NAME_COMPANY)
		;
	}

	public function listB2eMembersWithReadyStatus(int $entityId, int $limit, int $offset): Item\MemberCollection
	{
		$query = $this->getQueryForMemberCollectionReadyStatus($entityId);
		$query->setOffset($offset);
		$query->setLimit($limit);
		$query->setOrder(['ID' => 'DESC']);

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection())
			->setQueryTotal((int)$query->queryCountTotal())
		;
	}

	public function listB2eMembersForMyDocumentsGrid(
		int $entityId,
		int $limit,
		int $offset,
		?Item\MyDocumentsGrid\MyDocumentsFilter $filter = null,
	): Item\MemberCollection
	{
		$query = $this->getQueryForMemberCollectionNotWaitStatus($entityId, $filter);
		$query->setOffset($offset);
		$query->setLimit($limit);

		if ($filter === null || $filter->isNeedActionFeatureSort())
		{
			$readyForSigningWithDelimiters = implode("','", MemberStatus::getReadyForSigning());
			$query
				->addSelect(
					new Main\Entity\ExpressionField(
						name: 'STATUS_RELEVANCE',
						expression: "CASE 
						WHEN (
						 	(
								%s = '" . MemberStatus::READY . "'
								AND %s = {$this->convertRoleToInt(Role::SIGNER)}
								AND %s = '". Type\ProviderCode::GOS_KEY. "'
								AND %s IN ('". DocumentStatus::SIGNING ."', '". DocumentStatus::STOPPED ."')
							)
							OR 
							(
								%s IN ('$readyForSigningWithDelimiters')
								AND %s = '". DocumentStatus::SIGNING ."'
							)
						)
						THEN 0 
						ELSE 1
						END",
						buildFrom: [
								  'SIGNED',
								  'ROLE',
								  'DOCUMENT.PROVIDER_CODE',
								  'DOCUMENT.STATUS',
								  'SIGNED',
								  'DOCUMENT.STATUS',
							  ],
					),
				)
				->addOrder('STATUS_RELEVANCE')
			;
		}

		$query->addOrder('ID', 'DESC');

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection())
			->setQueryTotal((int)$query->queryCountTotal())
			;
	}

	public function isAnyMyDocumentsGridInSignedStatus(int $userId): bool
	{
		$filter = (new Item\MyDocumentsGrid\MyDocumentsFilter(
			statuses: [Type\MyDocumentsGrid\FilterStatus::SIGNED],
		));
		$query = $this->getQueryForMemberCollectionNotWaitStatus($userId, $filter);
		$query->setLimit(1)
			->setSelect(['ID'])
		;

		return !empty($query->fetch());
	}

	public function isAnyMyDocumentsGridInProgressStatus(int $userId): bool
	{
		$filter = (new Item\MyDocumentsGrid\MyDocumentsFilter(
			statuses: [Type\MyDocumentsGrid\FilterStatus::IN_PROGRESS],
		));
		$query = $this->getQueryForMemberCollectionNotWaitStatus($userId, $filter);
		$query->setLimit(1)
			->setSelect(['ID'])
		;

		return !empty($query->fetch());
	}

	public function getSecondSideMembersForMyDocumentsGrid(
		array $documentIds,
		array $memberIds,
	): Item\MemberCollection
	{
		$filter = Query::filter()
			->logic('or')
			->where(
				Query::filter()
					->logic('and')
					->whereIn('DOCUMENT.STATUS', [DocumentStatus::DONE, DocumentStatus::STOPPED])
					->where(
						Query::filter()
							->logic('or')
							->whereNot('SIGNED', MemberStatus::DONE)
							->where(Query::filter()->whereColumn('PART', 'DOCUMENT.PARTIES'))
					)
				,
			)
			->where(
				Query::filter()
					->logic('and')
					->where('SIGNED', MemberStatus::READY)
				,
			);

		$query =  Internal\MemberTable
			::query()
			->addSelect('*')
			->where($filter)
			->whereIn('DOCUMENT_ID', $documentIds)
			->whereNotIn('ID', $memberIds)
			->where('DOCUMENT.ENTITY_TYPE', '=', Type\Document\EntityType::SMART_B2E)
		;
		$query->setOrder(['PART' => 'ASC']);

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection())
			;
	}

	public function getTotalMemberCollectionCountWithNotWaitStatus(
		int $entityId,
		?Item\MyDocumentsGrid\MyDocumentsFilter $filter = null,
	): int
	{
		$query = $this->getQueryForMemberCollectionNotWaitStatus($entityId, $filter);

		return (int)$query->queryCountTotal();
	}

	public function getQueryForMemberCollectionNotWaitStatus(
		int $userId,
		?Item\MyDocumentsGrid\MyDocumentsFilter $filter = null,
	): Query
	{
		$baseFilter = Query::filter()
			->logic('or')
			->where(
				Query::filter()
					->logic('and')
					->where('ENTITY_TYPE', '=', EntityType::USER)
					->where('ENTITY_ID', $userId)
				,
			)
			->where(
				Query::filter()
					->logic('and')
					->where('ENTITY_TYPE', '=', EntityType::COMPANY)
					->where('ROLE', '=', $this->convertRoleToInt(Role::ASSIGNEE))
					->where('DOCUMENT.REPRESENTATIVE_ID', '=', $userId)
				,
			);

		$filterForStopped = Query::filter()
			->logic('and')
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->where('SIGNED', MemberStatus::STOPPED)
			->where(Query::filter()
				->logic('or')
				->where('DOCUMENT.INITIATED_BY_TYPE', Type\Document\InitiatedByType::COMPANY->toInt())
				->where(
					Query::filter()
						->logic('and')
						->where('DOCUMENT.INITIATED_BY_TYPE', Type\Document\InitiatedByType::EMPLOYEE->toInt())
						->whereColumn('DOCUMENT.STOPPED_BY_ID', 'ENTITY_ID')
				)
			)
		;

		$readyInDoneDocuments = Query::filter()
			->logic('and')
			->whereIn('DOCUMENT.STATUS', DocumentStatus::getFinalStatuses())
			->whereIn('SIGNED', MemberStatus::getReadyForSigning())
			->whereNot(
				Query::filter()
					->logic('and')
					->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
					->where('DOCUMENT.PROVIDER_CODE', Type\ProviderCode::GOS_KEY)
			)
		;

		$excludeStopped = Query::filter()
			->logic('or')
			->where($filterForStopped)
			->where($readyInDoneDocuments);

		$query = Internal\MemberTable
			::query()
			->addSelect('*')
			->whereNot('SIGNED', MemberStatus::WAIT)
			->where('DOCUMENT.ENTITY_TYPE', '=', Type\Document\EntityType::SMART_B2E)
			->where($baseFilter)
			->whereNot($excludeStopped)
			;

		if ($filter?->role === Type\MyDocumentsGrid\ActorRole::INITIATOR)
		{
			$query
				->where('DOCUMENT.INITIATED_BY_TYPE', Type\Document\InitiatedByType::EMPLOYEE->toInt())
				->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			;
		}
		elseif ($filter?->role === Type\MyDocumentsGrid\ActorRole::REVIEWER)
		{
			$query->where('ROLE', $this->convertRoleToInt(Role::REVIEWER));
		}
		elseif ($filter?->role === Type\MyDocumentsGrid\ActorRole::EDITOR)
		{
			$query->where('ROLE', $this->convertRoleToInt(Role::EDITOR));
		}
		elseif ($filter?->role === Type\MyDocumentsGrid\ActorRole::SIGNER)
		{
			$query->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
				  ->where('DOCUMENT.INITIATED_BY_TYPE', Type\Document\InitiatedByType::COMPANY->toInt())
			;
		}
		elseif ($filter?->role === Type\MyDocumentsGrid\ActorRole::ASSIGNEE)
		{
			$query->where('ROLE', $this->convertRoleToInt(Role::ASSIGNEE));
		}

		if ($filter?->initiators)
		{
			$query->whereIn('DOCUMENT.CREATED_BY_ID', $filter->initiators);
		}

		if ($filter?->editors)
		{
			$this->appendMemberExistConditionByRole(Role::EDITOR, $filter->editors, $query);
		}

		if ($filter?->reviewers)
		{
			$this->appendMemberExistConditionByRole(Role::REVIEWER, $filter->reviewers, $query);
		}

		if ($filter?->signers)
		{
			$this->appendMemberExistConditionByRole(Role::SIGNER, $filter->signers, $query);
		}

		if ($filter?->assignees)
		{
			$query->whereIn('DOCUMENT.REPRESENTATIVE_ID', $filter->assignees);
		}

		if ($filter?->assigneesOrSigners)
		{
			$this->appendAssigneeOrSignerCondition($filter->assigneesOrSigners, $query);
		}

		if ($filter?->companies)
		{
			$subQuery = Internal\MemberTable::query()
				->setCustomBaseTableAlias("sign_internal_member_company")
				->where('DOCUMENT_ID', new \Bitrix\Main\DB\SqlExpression('%s'))
				->where('ENTITY_TYPE', EntityType::COMPANY)
				->whereIn('ENTITY_ID', $filter->companies)
			;
			$query->whereExpr("EXISTS({$subQuery->getQuery()})", ['DOCUMENT_ID']);
		}

		if ($filter?->statuses)
		{
			$statusesFilter = Query::filter()->logic('or');
			foreach ($filter->statuses as $status)
			{
				$this->appendStatusCondition($status, $statusesFilter, $userId);
			}
			$query->where($statusesFilter);
		}

		if ($filter?->dateModifyFrom)
		{
			$query->where('DATE_MODIFY', '>=', $filter->dateModifyFrom);
		}

		if ($filter?->dateModifyTo)
		{
			$query->where('DATE_MODIFY', '<=', $filter->dateModifyTo);
		}

		if ($filter?->text)
		{
			$query->whereLike('DOCUMENT.TITLE', "%$filter->text%");
		}

		// Annulled member records are excluded by default (empty filter / "signed"
		// mode) and only surface when the ANNULLED status is explicitly requested.
		// The mark lives on the member record (base table), so filtering is done on
		// ANNULLED directly rather than on the document.
		//
		// The exclusion itself is gated by the feature flag: the ANNULLED filter value
		// and its preset are offered only while the feature is on, so filtering without
		// the flag would hide already-marked records from the employee with no way to
		// bring them back. Switching the feature off must restore the pre-feature view.
		$wantsAnnulled = $filter !== null
			&& in_array(Type\MyDocumentsGrid\FilterStatus::ANNULLED, $filter->statuses, true);
		if (!$wantsAnnulled && FeatureResolver::instance()->released('kedoDocumentAnnul'))
		{
			$query->where('ANNULLED', false);
		}

		return $query;
	}

	private function appendMemberExistConditionByRole(string $role, array $userIds, Query $query): void
	{
		$subQuery = $this->getMemberExistsSubqueryByRole($role, $userIds);
		$query->whereExpr("EXISTS({$subQuery->getQuery()})", ['DOCUMENT_ID']);
	}

	private function getMemberExistsSubqueryByRole(string $role, array $userIds): Query
	{
		return Internal\MemberTable::query()
			->setCustomBaseTableAlias("sign_internal_member_{$role}")
			->where('DOCUMENT_ID', new \Bitrix\Main\DB\SqlExpression('%s'))
			->where('ENTITY_TYPE', EntityType::USER)
			->where('ROLE', $this->convertRoleToInt($role))
			->whereIn('ENTITY_ID', $userIds)
		;
	}

	private function appendAssigneeOrSignerCondition(array $userIds, Query $query): void
	{
		$subQuery = $this->getMemberExistsSubqueryByRole(Role::SIGNER, $userIds);
		$query->where(Query::filter()
		   ->logic('or')
		   ->whereExpr("EXISTS({$subQuery->getQuery()})", ['DOCUMENT_ID'])
		   ->whereIn('DOCUMENT.REPRESENTATIVE_ID', $userIds)
		);
	}

	private function appendStatusCondition(
		Type\MyDocumentsGrid\FilterStatus $status,
		ConditionTree $statusesFilter,
		int $userId,
	): void
	{
		match ($status)
		{
			Type\MyDocumentsGrid\FilterStatus::SIGNED => $this->appendSignedStatusCondition($statusesFilter),
			Type\MyDocumentsGrid\FilterStatus::MY_SIGNED => $this->appendMySignedStatusCondition($statusesFilter),
			Type\MyDocumentsGrid\FilterStatus::IN_PROGRESS => $this->appendInProgressStatusCondition($statusesFilter),
			Type\MyDocumentsGrid\FilterStatus::NEED_ACTION => $this->appendNeedActionStatusCondition($statusesFilter),
			Type\MyDocumentsGrid\FilterStatus::MY_REVIEW => $this->appendMyReviewStatusCondition($statusesFilter),
			Type\MyDocumentsGrid\FilterStatus::MY_EDITED => $this->appendMyEditedStatusCondition($statusesFilter),
			Type\MyDocumentsGrid\FilterStatus::MY_STOPPED => $this->appendMyStoppedStatusCondition($statusesFilter, $userId),
			Type\MyDocumentsGrid\FilterStatus::STOPPED => $this->appendStoppedStatusCondition($statusesFilter),
			Type\MyDocumentsGrid\FilterStatus::ANNULLED => $this->appendAnnulledStatusCondition($statusesFilter),
			Type\MyDocumentsGrid\FilterStatus::MY_ACTION_DONE => $this->appendMyActionDoneStatusCondition($statusesFilter),
		};
	}

	private function appendAnnulledStatusCondition(ConditionTree $statusesFilter): void
	{
		$statusesFilter->where('ANNULLED', true);
	}

	private function appendSignedStatusCondition(ConditionTree $statusesFilter): void
	{
		$statusesFilter->where(
			Query::filter()
				 ->logic('and')
				 ->where('SIGNED', MemberStatus::DONE)
				 ->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
				 ->where(
					 Query::filter()
						  ->logic('or')
						  ->where('PART', '>', '1')
						  ->where('DOCUMENT.STATUS', DocumentStatus::DONE),
				 )
			,
		);
	}

	private function appendMySignedStatusCondition(ConditionTree $statusesFilter): void
	{
		$statusesFilter->where(
			Query::filter()
				->logic('or')
				->where(
					Query::filter()
						->logic('and')
						->whereIn('SIGNED', [MemberStatus::DONE])
						->where('ROLE', $this->convertRoleToInt(Role::ASSIGNEE))
						->whereIn('DOCUMENT.STATUS', [DocumentStatus::SIGNING, DocumentStatus::DONE, DocumentStatus::STOPPED])
				)
				->where(
					Query::filter()
						->logic('and')
						->whereIn('SIGNED', [MemberStatus::STOPPED])
						->where('ROLE', $this->convertRoleToInt(Role::ASSIGNEE))
						->whereIn('DOCUMENT.STATUS', [DocumentStatus::STOPPED, DocumentStatus::DONE])
						// if someone signed in by-company scenario, document not considered stopped
						->where('DOCUMENT.INITIATED_BY_TYPE', Type\Document\InitiatedByType::COMPANY->toInt())
						->whereExpr("EXISTS({$this->getSomeSignedSubQuery()->getQuery()})", ['DOCUMENT_ID']),
				)
		);
	}

	private function appendInProgressStatusCondition(ConditionTree $statusesFilter): void
	{
		$statusesFilter->where(
			Query::filter()
				 ->logic('or')
				 ->where(
					 Query::filter()
						  ->logic('and')
						  ->where('SIGNED', MemberStatus::READY)
						  ->where('ROLE', '=', $this->convertRoleToInt(Role::SIGNER))
						  ->where('DOCUMENT.PROVIDER_CODE', Type\ProviderCode::GOS_KEY)
						  ->whereIn('DOCUMENT.STATUS', [DocumentStatus::SIGNING, DocumentStatus::STOPPED]),
				 )
				 ->where(
					 Query::filter()
						  ->logic('and')
						  ->whereIn('SIGNED', MemberStatus::getReadyForSigning())
						  ->where('DOCUMENT.STATUS', DocumentStatus::SIGNING),
				 )
				 ->where(
					 Query::filter()
						  ->logic('and')
						  ->where('SIGNED', MemberStatus::DONE)
						  ->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
						  ->where('DOCUMENT.STATUS', DocumentStatus::SIGNING)
						  ->where('PART', 1),
				 ),
		);
	}

	private function appendNeedActionStatusCondition(ConditionTree $statusesFilter): void
	{
		$statusesFilter->where(
			Query::filter()
				 ->logic('or')
				 ->where(
					 Query::filter()
						  ->logic('and')
						  ->where('SIGNED', MemberStatus::READY)
						  ->where('ROLE', '=', $this->convertRoleToInt(Role::SIGNER))
						  ->where('DOCUMENT.PROVIDER_CODE', Type\ProviderCode::GOS_KEY)
						  ->whereIn('DOCUMENT.STATUS', DocumentStatus::getEnding()),
				 )
				 ->where(
					 Query::filter()
						  ->logic('and')
						  ->whereIn('SIGNED', MemberStatus::getReadyForSigning())
						  ->where('DOCUMENT.STATUS', DocumentStatus::SIGNING),
				 ),
		);
	}

	private function appendMyReviewStatusCondition(ConditionTree $statusesFilter): void
	{
		$statusesFilter->where(
			Query::filter()
				 ->logic('and')
				 ->where('SIGNED', MemberStatus::DONE)
				 ->where('ROLE', $this->convertRoleToInt(Role::REVIEWER)),
		);
	}

	private function appendMyEditedStatusCondition(ConditionTree $statusesFilter): void
	{
		$statusesFilter->where(
			Query::filter()
				 ->logic('and')
				 ->where('SIGNED', MemberStatus::DONE)
				 ->where('ROLE', $this->convertRoleToInt(Role::EDITOR)),
		);
	}

	private function appendMyStoppedStatusCondition(ConditionTree $statusesFilter, int $userId): void
	{
		$statusesFilter->where(
			Query::filter()
				 ->logic('or')
				 ->where('SIGNED', MemberStatus::REFUSED)
				 ->where(
					 Query::filter()
						  ->logic('and')
						  ->where('DOCUMENT.STATUS', DocumentStatus::STOPPED)
						  ->where('DOCUMENT.STOPPED_BY_ID', $userId)
						  // if someone signed in by-company scenario, document not considered stopped
						  ->where(
							  Query::filter()
								  ->logic('or')
								  ->where('DOCUMENT.INITIATED_BY_TYPE', Type\Document\InitiatedByType::EMPLOYEE->toInt())
								  ->whereExpr("NOT EXISTS({$this->getSomeSignedSubQuery()->getQuery()})", ['DOCUMENT_ID']),
						  )
				 ),
		);
	}

	private function appendStoppedStatusCondition(ConditionTree $statusesFilter): void
	{
		$statusesFilter->where(
			Query::filter()
				 ->logic('and')
				 ->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
				 ->where(Query::filter()
						->logic('or')
						->where('SIGNED', MemberStatus::STOPPED)
					 	->where(Query::filter()
							->logic('and')
							->where('DOCUMENT.STATUS', DocumentStatus::STOPPED)
							->where('DOCUMENT.INITIATED_BY_TYPE', Type\Document\InitiatedByType::EMPLOYEE->toInt())
							->where('SIGNED', MemberStatus::DONE)
					 	)
				 )
			,
		);
	}

	private function appendMyActionDoneStatusCondition(ConditionTree $statusesFilter): void
	{
		$notSignerRoles = [
			$this->convertRoleToInt(Role::ASSIGNEE),
			$this->convertRoleToInt(Role::REVIEWER),
			$this->convertRoleToInt(Role::EDITOR),
		];
		$statusesFilter->where(
			Query::filter()
				 ->logic('or')
				 ->where('SIGNED', MemberStatus::REFUSED)
				 ->where(
					 Query::filter()
						  ->logic('and')
						  ->whereIn('SIGNED', [MemberStatus::DONE, MemberStatus::STOPPED])
						  ->whereIn('ROLE', $notSignerRoles)
				 )
		);
	}

	public function listB2eMembersWithReadyStatusByDocumentIds(array $documentIds, int $entityId): Item\MemberCollection
	{
		$query = $this->getQueryForMemberCollectionReadyStatus($entityId)
			->whereIn('DOCUMENT_ID', $documentIds)
		;

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection())
			->setQueryTotal((int)$query->queryCountTotal())
		;
	}

	public function getCountForCurrentUserAction(int $entityId): int
	{
		return (int)$this->getQueryForMemberCollectionReadyStatus($entityId)->queryCountTotal();
	}

	public function listB2eSigningByDocumentIdAndStatuses(int $documentId, array $statuses, int $limit = 1): Item\MemberCollection
	{
		$result = Internal\MemberTable::query()
			->addSelect('*')
			->where('ENTITY_TYPE', EntityType::USER)
			->whereIn('SIGNED', $statuses)
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->setLimit($limit)
		;

		return $this->extractItemCollectionFromModelCollection($result->fetchCollection())
			->setQueryTotal((int)$result->queryCountTotal())
		;
	}

	public function listB2eStoppedByDocumentId(int $documentId, int $limit = 3): Item\MemberCollection
	{
		$filter = Query::filter()
			->logic('or')
			->where(
				Query::filter()
					->logic('and')
					->whereIn('SIGNED', [MemberStatus::REFUSED, MemberStatus::STOPPED]),
			)->where(
				Query::filter()
					->logic('and')
					->where('DOCUMENT.STATUS', DocumentStatus::STOPPED)
					->whereNot('SIGNED', MemberStatus::DONE),
			);

		$result = Internal\MemberTable::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->where($filter)
			->setLimit($limit)
		;

		return $this->extractItemCollectionFromModelCollection($result->fetchCollection())
			->setQueryTotal((int)$result->queryCountTotal())
		;
	}

	private function getQueryForMemberCollectionReadyStatus(int $entityId): Query
	{
		$filter = Query::filter()
			->logic('or')
			->where(
				Query::filter()
					->logic('and')
					->where('ENTITY_TYPE', '=', EntityType::USER)
					->where('ENTITY_ID', $entityId)
					->whereIn('DOCUMENT.STATUS', [DocumentStatus::SIGNING, DocumentStatus::STOPPED])
					->where(
						Query::filter()
							->logic('or')
							->where(Query::filter()
										 ->logic('and')
										 ->where('SIGNED', MemberStatus::READY)
										 ->where('ROLE', '=', $this->convertRoleToInt(Role::SIGNER))
										 ->where('DOCUMENT.PROVIDER_CODE', Type\ProviderCode::GOS_KEY)
										 ->whereIn('DOCUMENT.STATUS', [DocumentStatus::SIGNING, DocumentStatus::STOPPED]),
							)
							->where(Query::filter()
										 ->logic('and')
										 ->whereIn('SIGNED', MemberStatus::getReadyForSigning())
										 ->where('DOCUMENT.STATUS', DocumentStatus::SIGNING),
							),
					)
				,
			)
			->where(
				Query::filter()
					->logic('and')
					->where('ENTITY_TYPE', '=', EntityType::COMPANY)
					->where('ROLE', '=', $this->convertRoleToInt(Role::ASSIGNEE))
					->where('DOCUMENT.REPRESENTATIVE_ID', '=', $entityId)
					->where('DOCUMENT.STATUS', DocumentStatus::SIGNING)
				,
		);

		return Internal\MemberTable
			::query()
			->addSelect('*')
			->whereIn('SIGNED', MemberStatus::getReadyForSigning())
			->where('DOCUMENT.ENTITY_TYPE', '=', Type\Document\EntityType::SMART_B2E)
			->where($filter)
		;
	}

	/**
	 * @param Internal\MemberCollection $modelCollection
	 *
	 * @return array|array<int, Main\EO_User>
	 *
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	private function getUserModels(Internal\MemberCollection $modelCollection): array
	{
		$userIds = $this->getUserIds($modelCollection);

		if (empty($userIds))
		{
			return [];
		}

		$userModels = Main\UserTable::query()
			->whereIn('ID', $userIds)
			->setSelect(self::USER_CACHE_FIELDS)
			->fetchCollection()
		;

		$userModelsById = [];
		foreach ($userModels as $userModel)
		{
			$userModelsById[$userModel->getId()] = $userModel;
		}

		return $userModelsById;
	}

	/**
	 * @param Internal\MemberCollection $modelCollection
	 * @return array|array<int>
	 */
	private function getUserIds(Internal\MemberCollection $modelCollection): array
	{
		$userIds = [];
		foreach ($modelCollection as $member)
		{
			if ($member->getEntityType() !== EntityType::USER)
			{
				continue;
			}

			$entityId = $member->getEntityId();
			if ($entityId)
			{
				$userIds[] = $entityId;
			}
		}

		return $userIds;
	}

	public function setUserCache(?UserCache $cache = null): static
	{
		$this->userCache = $cache;

		return $this;
	}

	public function getUserCache(): ?UserCache
	{
		return $this->userCache;
	}

	public function setAsVerified(Item\Member $item): UpdateResult
	{
		if (!$item->id)
		{
			return (new UpdateResult())->addError(new Error('Document not found'));
		}

		$member = Internal\MemberTable::getById($item->id)->fetchObject();
		if ($member === null)
		{
			return (new UpdateResult())->addError(new Error('Member not found'));
		}

		$member->setVerified('Y');

		return $member->save();
	}

	public function listNotConfiguredByDocumentId(int $documentId, int $limit = 30): Item\MemberCollection
	{
		$models = $this->getNotConfiguredMembersQueryByDocumentId($documentId)
			->addSelect('*')
			->setLimit($limit)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	public function markAsConfigured(Item\MemberCollection $members): Main\Result
	{
		$result = Internal\MemberTable::updateMulti($members->getIds(), [
			'CONFIGURED' => 1,
		]);

		if ($result->isSuccess())
		{
			foreach ($members as $member)
			{
				$member->configured = 1;
			}
		}

		return $result;
	}

	public function countByDocumentId(int $documentId): int
	{
		return (int)Internal\MemberTable::query()
			->where('DOCUMENT_ID', $documentId)
			->queryCountTotal()
		;
	}

	public function countNotConfiguredByDocumentId(int $documentId): int
	{
		return (int)$this->getNotConfiguredMembersQueryByDocumentId($documentId)
						 ->queryCountTotal()
		;
	}

	/**
	 * @return Internal\EO_Member_Query
	 */
	private function getNotConfiguredMembersQueryByDocumentId(int $documentId): Query
	{
		return Internal\MemberTable::query()
			   ->where('DOCUMENT_ID', $documentId)
			   ->where(Query::filter()
							->logic('or')
							->whereNull('CONFIGURED')
							->where('CONFIGURED', 0),
			   )
		;
	}

	public function addMany(Item\MemberCollection $members): Main\Result
	{
		$ormCollection = new Internal\MemberCollection();
		foreach ($members->toArray() as $item)
		{
			$item->uid = $this->generateUid();

			$now = new DateTime();
			$filledMemberEntity = $this
				->extractModelFromItem($item)
				->setDateCreate($now)
				->setDateModify($now)
			;
			$ormCollection->add($filledMemberEntity);
		}

		return $ormCollection->save(true);
	}

	public function countMembersByDocumentIdAndRoleAndStatus(
		int $documentId,
		array $statuses = [],
		string $role = Role::SIGNER,
	): int
	{
		$query = Internal\MemberTable
			::query()
			->whereIn('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt($role))
		;

		if ($statuses)
		{
			$query->whereIn('SIGNED', $statuses);
		}

		return (int)$query->queryCountTotal();
	}

	public function listByDocumentIdAndRoleAndStatus(
		int $documentId,
		string $role,
		int $limit = 0,
		array $statuses = []): Item\MemberCollection
	{
		$query = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt($role))
		;
		if ($limit)
		{
			$query->setLimit($limit);
		}

		if ($statuses)
		{
			$query->whereIn('SIGNED', $statuses);
		}

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection());
	}

	public function isSignerExistsByDocumentIdInStatus(int $documentId, array $statuses): bool
	{
		$query = Internal\MemberTable
			::query()
			->addSelect('ID')
			->setLimit(1)
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->whereIn('SIGNED', $statuses)
		;

		return !empty($query->fetch());
	}

	public function isSignerExistsByDocumentIdNotInStatus(int $documentId, array $statuses): bool
	{
		$query = Internal\MemberTable
			::query()
			->addSelect('ID')
			->setLimit(1)
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->whereNotIn('SIGNED', $statuses)
		;

		return !empty($query->fetch());
	}

	/**
	 * @param array<int|string, int> $relevance
	 */
	private function getExpressionByRelevance(array $relevance): string
	{
		global $DB;
		$function = static function(string|int $option, int $relevance) use ($DB): string {
			return "WHEN '{$DB->ForSql($option)}' THEN $relevance";
		};
		$conditionString = implode(' ', array_map($function, array_keys($relevance), $relevance));
		$maxRelevance = max($relevance) + 1;

		return "CASE %s {$conditionString} ELSE {$maxRelevance} END";
	}

	public function listByDocumentIdWithRoles(int $documentId, array $memberRoles): Item\MemberCollection
	{
		$roleIds = array_map(fn(string $role) => $this->convertRoleToInt($role), $memberRoles);
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->whereIn('ROLE', $roleIds)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	/**
	 * @param int $documentId
	 * @param list<Role> $memberRoles
	 * @param list<MemberStatus::*> $memberStatuses
	 */
	public function listByDocumentIdWithRolesAndStatuses(int $documentId, array $memberRoles, array $memberStatuses): Item\MemberCollection
	{
		$roleIds = array_map(fn(string $role) => $this->convertRoleToInt($role), $memberRoles);
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->whereIn('ROLE', $roleIds)
			->whereIn('SIGNED', $memberStatuses)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	/**
	 * @param list<MemberStatus::*> $memberStatuses
	 */
	public function listByDocumentIdWithStatuses(int $documentId, array $memberStatuses): Item\MemberCollection
	{
		$models = Internal\MemberTable
			::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->whereIn('SIGNED', $memberStatuses)
			->fetchCollection()
		;

		return $this->extractItemCollectionFromModelCollection($models);
	}

	public function existsByDocumentIdWithRoleAndStatus(int $documentId, string $role, string $status): bool
	{
		$roleInt = $this->convertRoleToInt($role);

		$model = Internal\MemberTable::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $roleInt)
			->where('SIGNED', $status)
			->setLimit(1)
			->fetchObject()
		;

		return $model !== null;
	}

	public function existsByDocumentIdWithRoleStatusAndAnnulled(
		int $documentId,
		string $role,
		string $status,
		bool $annulled,
	): bool
	{
		$model = Internal\MemberTable::query()
			->addSelect('ID')
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt($role))
			->where('SIGNED', $status)
			->where('ANNULLED', $annulled)
			->setLimit(1)
			->fetchObject()
		;

		return $model !== null;
	}

	/**
	 * Records of the document the annulment switches: the given role and status, with
	 * the mark still opposite to the target state.
	 *
	 * Only the fields the caller needs are selected, and the items are built from those
	 * fields alone instead of going through the full extraction: a document can hold
	 * thousands of signers and the collection outlives the request in the deferred card
	 * fan-out, so neither the whole record nor the name resolution of every member is
	 * worth fetching here. The id is among them because this very set is what the write
	 * updates - see annulByIds().
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::getUserIdForMember() the only
	 *      reader of the recipient fields - it needs entityType, entityId and documentId.
	 */
	public function listAnnulmentNotificationTargets(
		int $documentId,
		string $role,
		string $status,
		bool $annulled,
	): Item\MemberCollection
	{
		$rows = Internal\MemberTable::query()
			->setSelect(['ID', 'DOCUMENT_ID', 'ENTITY_TYPE', 'ENTITY_ID'])
			->where('DOCUMENT_ID', $documentId)
			->where('ROLE', $this->convertRoleToInt($role))
			->where('SIGNED', $status)
			->where('ANNULLED', $annulled)
			->fetchAll()
		;

		$items = array_map(
			static fn(array $row): Item\Member => new Item\Member(
				documentId: (int)$row['DOCUMENT_ID'],
				id: (int)$row['ID'],
				entityType: $row['ENTITY_TYPE'],
				entityId: $row['ENTITY_ID'] === null ? null : (int)$row['ENTITY_ID'],
			),
			$rows,
		);

		return new Item\MemberCollection(...$items);
	}

	/**
	 * Signer records of the given documents, carrying the fields a grid row needs to
	 * point at the signing it speaks about: the annul endpoints address the uid, and
	 * the eligibility predicate reads the status.
	 *
	 * Narrow on purpose, like listAnnulmentNotificationTargets(): the items are built
	 * from the selected fields alone, with no user name or CRM lookup, because that
	 * cost is exactly what a per-page resolve must avoid. The status is passed
	 * explicitly - Item\Member defaults it to WAIT, and a dropped value would read as
	 * "no completed signer" for every document.
	 *
	 * The status is not filtered in the query: the completeness predicate belongs to
	 * the resolver, so it stays in one place and matches the server-side checks.
	 *
	 * @param list<int> $documentIds
	 */
	public function listSignersByDocumentIds(array $documentIds): Item\MemberCollection
	{
		$ids = array_values(array_unique(array_filter(
			array_map('intval', $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if ($ids === [])
		{
			return new Item\MemberCollection();
		}

		$signerRole = $this->convertRoleToInt(Role::SIGNER);
		$members = [];
		foreach (array_chunk($ids, self::SIGNERS_BY_DOCUMENTS_BATCH_SIZE) as $batchIds)
		{
			$rows = Internal\MemberTable::query()
				->setSelect(['ID', 'DOCUMENT_ID', 'UID', 'SIGNED', 'ANNULLED'])
				->whereIn('DOCUMENT_ID', $batchIds)
				->where('ROLE', $signerRole)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$members[] = new Item\Member(
					documentId: (int)$row['DOCUMENT_ID'],
					id: (int)$row['ID'],
					uid: $row['UID'],
					status: (string)$row['SIGNED'],
					role: Role::SIGNER,
					annulled: (bool)$row['ANNULLED'],
				);
			}
		}

		return new Item\MemberCollection(...$members);
	}

	/**
	 * Switches the annulment mark on the given members, recording who switched it and
	 * when - see buildAnnulmentFields().
	 *
	 * Addressed by id instead of by a filter over the document: a filter would resolve
	 * the set a second time and could reach records the caller never resolved. Of that
	 * set, the ones this call really switched are named back - see
	 * switchAnnulmentMark().
	 *
	 * @param list<int|null> $memberIds
	 *
	 * @return Main\Result|AnnulmentSwitchResult Records this very call switched, or the
	 *         failure of the write itself. An empty set is nothing to switch, not a
	 *         failure.
	 */
	public function annulByIds(array $memberIds, bool $annul, ?int $userId): Main\Result|AnnulmentSwitchResult
	{
		$ids = array_values(array_filter(array_map('intval', $memberIds)));
		if (empty($ids))
		{
			return new AnnulmentSwitchResult([]);
		}

		return $this->switchAnnulmentMark($ids, $annul, $userId);
	}

	/**
	 * Switches the annulment mark on one member, recording who switched it and when -
	 * see buildAnnulmentFields().
	 *
	 * Written straight by id: the caller knows the four fields to store, while the
	 * item write path would re-read the whole record first - once per member on the
	 * batch path.
	 *
	 * @return Main\Result|AnnulmentSwitchResult The one record when this very call
	 *         switched the mark, none when the record already held the target state, or
	 *         the failure of the write itself.
	 */
	public function annulById(int $memberId, bool $annul, ?int $userId): Main\Result|AnnulmentSwitchResult
	{
		// No such record can exist, so nothing was even attempted: that is a failure of
		// the call rather than a record left in the state it already held.
		if ($memberId <= 0)
		{
			return (new Main\Result())->addError(new Main\Error('Member id is invalid'));
		}

		return $this->switchAnnulmentMark([$memberId], $annul, $userId);
	}

	/**
	 * Names the records it switched, not just how many: the read resolves the ones
	 * still holding the state the mark is switched away from and locks them, and the
	 * write switches exactly those. Both statements run in one transaction, so nothing
	 * can switch a locked record in between and the returned set is what the caller
	 * addresses its side effects by.
	 *
	 * Reading the state without the lock and then updating by id alone would let two
	 * requests going the same way both write the columns, and the one that arrived
	 * second would name its own author and moment for a switch that had already
	 * happened - and would announce it to the recipients of the first one. Counting the
	 * rows a conditional update affected closes that gap for the number but still
	 * cannot name the rows, and an update that returns them is PostgreSQL-only; a
	 * locking read is portable.
	 *
	 * The transaction covers two statements addressed by primary key and nothing else,
	 * so the locks are held for the write alone. Failures come back as errors of the
	 * result rather than as exceptions, preparation of the statements included: the
	 * caller tells a failed write from a record nobody had to switch by the same result
	 * it reads the set from.
	 *
	 * @param list<int> $ids
	 */
	private function switchAnnulmentMark(array $ids, bool $annul, ?int $userId): Main\Result|AnnulmentSwitchResult
	{
		$connection = Internal\MemberTable::getEntity()->getConnection();
		$transactionStarted = false;
		$switchedIds = [];

		try
		{
			$connection->startTransaction();
			$transactionStarted = true;

			$switchedIds = $this->lockSwitchableMembers($connection, $ids, $annul);
			if ($switchedIds !== [])
			{
				$this->writeAnnulmentMark($connection, $switchedIds, $annul, $userId);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			if ($transactionStarted)
			{
				$this->rollbackAnnulmentTransaction($connection);
			}

			return (new Main\Result())->addError(new Main\Error($e->getMessage()));
		}

		if ($switchedIds !== [])
		{
			// The ORM does this itself on its own write paths, and getByUid() reads
			// members through an hour-long query cache: without it an annulled member
			// would keep reading back as untouched.
			Internal\MemberTable::cleanCache();
		}

		return new AnnulmentSwitchResult($switchedIds);
	}

	/**
	 * Records of the given set that still hold the state the mark is switched away
	 * from, locked until the transaction ends. A record already in the target state is
	 * left alone and not returned, so the caller of the repository never announces a
	 * switch another request performed and attributed to itself.
	 *
	 * Raw SQL because the ORM has no locking read. Identifiers go through the sqlHelper
	 * and the condition through the ORM filter builder, so nothing is concatenated by
	 * hand, and both databases understand `FOR UPDATE`. Rows are locked in id order,
	 * like the folder relations are, so requests over overlapping sets queue up instead
	 * of deadlocking.
	 *
	 * @param list<int> $ids
	 *
	 * @return list<int>
	 */
	private function lockSwitchableMembers(Main\DB\Connection $connection, array $ids, bool $annul): array
	{
		$entity = Internal\MemberTable::getEntity();
		$sqlHelper = $connection->getSqlHelper();
		$idColumn = $sqlHelper->quote('ID');

		$where = Query::buildFilterSql(
			$entity,
			(new ConditionTree())
				->whereIn('ID', $ids)
				->where('ANNULLED', !$annul)
			,
		);

		$rows = $connection->query(
			'SELECT ' . $idColumn
			. ' FROM ' . $sqlHelper->quote(Internal\MemberTable::getTableName())
			. " WHERE {$where} ORDER BY {$idColumn} FOR UPDATE"
		)->fetchAll();

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Writes the mark and its attribution - see buildAnnulmentFields().
	 *
	 * Addressed by id alone: the records are locked by lockSwitchableMembers(), so the
	 * state they are switched away from can no longer change and repeating it as a
	 * condition would guard nothing.
	 *
	 * Raw SQL because the ORM addresses rows one primary key at a time - update() and
	 * updateMulti() would take a statement per record - while the whole set shares the
	 * four values written.
	 *
	 * @param list<int> $ids
	 */
	private function writeAnnulmentMark(Main\DB\Connection $connection, array $ids, bool $annul, ?int $userId): void
	{
		$entity = Internal\MemberTable::getEntity();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = Internal\MemberTable::getTableName();

		[$update, $binds] = $sqlHelper->prepareUpdate(
			$tableName,
			$this->buildAnnulmentFields($annul, $userId),
		);
		$where = Query::buildFilterSql($entity, (new ConditionTree())->whereIn('ID', $ids));

		$connection->queryExecute(
			'UPDATE ' . $sqlHelper->quote($tableName) . " SET {$update} WHERE {$where}",
			$binds,
		);
	}

	private function rollbackAnnulmentTransaction(Main\DB\Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (Main\DB\TransactionException)
		{
			// MySQL rolls back to the savepoint and then reports that nested rollbacks
			// are unsupported: this switch is undone, and the transaction around it stays
			// the caller's to decide on.
		}
	}

	/**
	 * The three annulment columns describe the last switch of the mark in either
	 * direction: ANNULLED is the state it was switched to, ANNULLED_BY_ID and
	 * DATE_ANNULLED name the author and the moment of that very switch. Removing the
	 * mark is therefore attributed the same way setting it is, and the pair is read
	 * together with the flag: annulled by someone, or un-annulled by someone.
	 *
	 * @return array<string, mixed>
	 */
	private function buildAnnulmentFields(bool $annul, ?int $userId): array
	{
		$now = new DateTime();

		return [
			'ANNULLED' => $annul,
			'ANNULLED_BY_ID' => $userId,
			'DATE_ANNULLED' => $now,
			'DATE_MODIFY' => $now,
		];
	}

	public function existsByDocumentIdWithReminderTypeNotEqual(int $documentId, ReminderType $reminderType): bool
	{
		$model = Internal\MemberTable::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $documentId)
			->whereNot('REMINDER_TYPE', $reminderType->toInt())
			->setLimit(1)
			->fetchObject()
		;

		return $model !== null;
	}

	public function updateMembersReminderTypeByRole(int $documentId, string $memberRole, ReminderType $reminderType): Main\Result
	{
		$members = $this->listByDocumentIdWithRole($documentId, $memberRole);
		$ids = $members->getIds();
		if (empty($ids))
		{
			return new Main\Result();
		}

		return Internal\MemberTable::updateMulti($ids, ['REMINDER_TYPE' => $reminderType->toInt()]);
	}

	/**
	 * Reminder frequency is set for a whole role at once, so it is read back by role. The first set
	 * type of a role wins: members created later with the default must not mask it, and a role with
	 * no frequency set is absent from the result.
	 *
	 * @see MemberRepository::updateMembersReminderTypeByRole
	 *
	 * @return array<string, ReminderType>
	 */
	public function listReminderTypesByDocumentIdIndexedByRole(int $documentId): array
	{
		// grouped in the database: a document of a synced department holds thousands of members, and
		// every one of them carries the frequency of its role
		$rows = Internal\MemberTable::query()
			->setSelect([
				'ROLE',
				'REMINDER_TYPE',
				new Main\Entity\ExpressionField('FIRST_MEMBER_ID', 'MIN(%s)', 'ID'),
			])
			->where('DOCUMENT_ID', $documentId)
			->whereNotNull('ROLE')
			->whereNot('REMINDER_TYPE', ReminderType::NONE->toInt())
			->setGroup(['ROLE', 'REMINDER_TYPE'])
			->setOrder(['FIRST_MEMBER_ID' => 'ASC'])
			->fetchAll()
		;

		$reminderTypes = [];
		foreach ($rows as $row)
		{
			$role = Role::tryFromInt((int)$row['ROLE']);
			$reminderType = ReminderType::tryFromInt((int)$row['REMINDER_TYPE']);
			if ($role === null || $reminderType === null)
			{
				continue;
			}

			$reminderTypes[$role] ??= $reminderType;
		}

		return $reminderTypes;
	}

	public function getByDocumentAndEntityType(int $id, string $entityType): ?Item\Member
	{
		$model = Internal\MemberTable::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $id)
			->where('ENTITY_TYPE', $entityType)
			->setLimit(1)
			->fetchObject()
		;

		return $model !== null ? $this->extractItemFromModel($model) : null;
	}

	public function getCompanyMemberByDocument(int $id): ?Item\Member
	{
		$model = Internal\MemberTable::query()
			->addSelect('*')
			->where('DOCUMENT_ID', $id)
			->whereIn('ENTITY_TYPE', [EntityType::ROLE, EntityType::COMPANY])
			->where('ROLE', $this->convertRoleToInt(Role::ASSIGNEE))
			->setLimit(1)
			->fetchObject()
		;

		return $model !== null ? $this->extractItemFromModel($model) : null;
	}

	/**
	 * @param int $documentId
	 *
	 * @return list<int>
	 */
	public function listUserIdsByDocumentId(int $documentId): array
	{
		return $this->listUniqueUserIdsByDocumentIds([$documentId]);
	}

	/**
	 * @param list<int> $excludedUserIds
	 *
	 * @return list<int>
	 */
	public function listSignerUserIdsByDocumentId(
		int $documentId,
		int $limit = self::MAX_SOURCE_USER_COUNT + 1,
		array $excludedUserIds = [],
	): array
	{
		return $this->listSignerUserIdsByDocumentIds([$documentId], $limit, $excludedUserIds);
	}

	/**
	 * @param list<int> $documentIds
	 * @param list<int> $excludedUserIds
	 *
	 * @return list<int>
	 */
	public function listSignerUserIdsByDocumentIds(
		array $documentIds,
		int $limit = self::MAX_SOURCE_USER_COUNT + 1,
		array $excludedUserIds = [],
	): array
	{
		$documentIds = array_values(array_unique(array_filter(
			array_map('intval', $documentIds),
			static fn(int $documentId): bool => $documentId > 0,
		)));
		$limit = min(max(0, $limit), self::MAX_SOURCE_USER_COUNT + 1);
		if ($documentIds === [] || $limit === 0)
		{
			return [];
		}

		$excludedUserIds = array_values(array_unique(array_filter(
			array_map('intval', $excludedUserIds),
			static fn(int $userId): bool => $userId > 0,
		)));
		$intranetUserQuery = UserTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', 'Y')
			->where('IS_REAL_USER', 'Y')
			->where('UF_DEPARTMENT', '!=', false)
		;

		$query = Internal\MemberTable::query()
			->setSelect(['ENTITY_ID'])
			->setDistinct()
			->whereIn('DOCUMENT_ID', $documentIds)
			->where('ENTITY_TYPE', EntityType::USER)
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->whereIn('ENTITY_ID', $intranetUserQuery)
			->setLimit($limit)
		;

		if ($excludedUserIds !== [])
		{
			$query->whereNotIn('ENTITY_ID', $excludedUserIds);
		}

		$result = $query->exec();
		$userIds = [];
		while ($row = $result->fetch())
		{
			$userIds[] = (int)$row['ENTITY_ID'];
		}

		return $userIds;
	}

	/**
	 * @param int $documentId
	 *
	 * @return array<int>
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function listUserIdsWithEmployeeIdIsNotSetByDocumentId(int $documentId, ?int $representativeId = null): array
	{
		$result = Internal\MemberTable::query()
			->setSelect(['ENTITY_ID', 'ENTITY_TYPE'])
			->setDistinct()
			->where('DOCUMENT_ID', $documentId)
			->whereNull('EMPLOYEE_ID')
			->exec()
		;

		$userIds = [];
		while ($row = $result->fetch())
		{
			$entityId = $row['ENTITY_ID'];
			if ($representativeId > 0 && $row['ENTITY_TYPE'] === EntityType::COMPANY)
			{
				$entityId = $representativeId;
			}

			$userIds[] = (int)$entityId;
		}

		return array_values(
			array_unique($userIds)
		);
	}

	private function getSomeSignedSubQuery(): Query	
	{
		return Internal\MemberTable::query()
			->setCustomBaseTableAlias("sign_internal_member_someone_signed")
			->where('DOCUMENT_ID', new \Bitrix\Main\DB\SqlExpression('%s'))
			->where('ROLE', Role::SIGNER)
			->whereNotIn('SIGNED', [MemberStatus::STOPPABLE_READY, MemberStatus::WAIT, MemberStatus::STOPPED, MemberStatus::REFUSED])
			->setLimit(1)
		;
	}

	/**
	 * @param list<int> $documentIds
	 *
	 * @return list<int>
	 */
	public function listUniqueUserIdsByDocumentIds(array $documentIds): array
	{
		if (empty($documentIds))
		{
			return [];
		}

		$result = Internal\MemberTable::query()
			->setSelect(['ENTITY_ID'])
			->setDistinct()
			->whereIn('DOCUMENT_ID', $documentIds)
			->where('ENTITY_TYPE', EntityType::USER)
			->exec()
		;

		$flatArray = [];
		while ($row = $result->fetch())
		{
			$flatArray[] = (int)$row['ENTITY_ID'];
		}

		return $flatArray;
	}

	/**
	 * @param Item\DocumentCollection $documents
	 *
	 * @return array<int, list<int>>
	 */
	public function listUserIdsWithEmployeeIdIsNotSetByDocumentIds(Item\DocumentCollection $documents): array
	{
		$result = Internal\MemberTable::query()
			  ->setSelect(['ENTITY_ID', 'ENTITY_TYPE', 'DOCUMENT_ID'])
			  ->setDistinct()
			  ->whereIn('DOCUMENT_ID', $documents->listIdsWithoutNull())
			  ->whereNull('EMPLOYEE_ID')
			  ->exec()
		;
		$representativesMap = [];
		foreach ($documents as $document)
		{
			$representativesMap[$document->id] = $document->representativeId;
		}

		$userIdsByDocumentIds = [];
		while ($row = $result->fetch())
		{
			$entityId = (int)$row['ENTITY_ID'];
			$documentId = (int)$row['DOCUMENT_ID'];
			if ($row['ENTITY_TYPE'] === EntityType::COMPANY)
			{
				$entityId = $representativesMap[$documentId] ?? 0;
			}

			$userIdsByDocumentIds[$documentId][] = $entityId;
		}

		foreach ($userIdsByDocumentIds as $documentId => $userIds)
		{
			$userIdsByDocumentIds[$documentId] = array_values(array_unique(array_filter($userIds)));
		}

		return $userIdsByDocumentIds;
	}

	public function listDoneSignersWithoutResultFile(): Item\MemberCollection
	{
		$query = Internal\MemberTable::query()
			->addSelect('*')
			->registerRuntimeField('',
				new \Bitrix\Main\ORM\Fields\Relations\Reference(
					'RESULT_FILE',
					Internal\FileTable::getEntity(),
					\Bitrix\Main\ORM\Query\Join::on('this.ID', 'ref.ENTITY_ID')
						->where('ref.ENTITY_TYPE_ID', \Bitrix\Sign\Type\EntityType::MEMBER)
						->where('ref.CODE', \Bitrix\Sign\Type\EntityFileCode::SIGNED)
					,
					['join_type' => \Bitrix\Main\ORM\Query\Join::TYPE_LEFT]
				)
			)
			->where('SIGNED', MemberStatus::DONE)
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->whereNull('RESULT_FILE.ID');

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection());
	}

	public function listDoneAssigneesWithoutResultFile(): Item\MemberCollection
	{
		$query = Internal\MemberTable::query()
			->addSelect('*')
			->registerRuntimeField('',
				new \Bitrix\Main\ORM\Fields\Relations\Reference(
					'RESULT_FILE',
					Internal\FileTable::getEntity(),
					\Bitrix\Main\ORM\Query\Join::on('this.ID', 'ref.ENTITY_ID')
						->where('ref.ENTITY_TYPE_ID', \Bitrix\Sign\Type\EntityType::MEMBER)
						->where('ref.CODE', \Bitrix\Sign\Type\EntityFileCode::SIGNED)
					,
					['join_type' => \Bitrix\Main\ORM\Query\Join::TYPE_LEFT]
				)
			)
			->registerRuntimeField('',
				(new Main\ORM\Fields\Relations\Reference(
					"DOCUMENT",
					Internal\DocumentTable::getEntity(),
					Main\ORM\Query\Join::on("this.DOCUMENT_ID", 'ref.ID'),
					[
						'join_type' => Main\ORM\Query\Join::TYPE_INNER,
					],
				)),
			)
			->where('SIGNED', MemberStatus::DONE)
			->where('ROLE', $this->convertRoleToInt(Role::ASSIGNEE))
			->where('DOCUMENT.SCHEME', Type\Document\SchemeType::ORDER_ID)
			->whereNull('RESULT_FILE.ID');

		return $this->extractItemCollectionFromModelCollection($query->fetchCollection());
	}

	/**
	 * @param int[] $documentIds
	 * @return int[]
	 */
	public function getDocumentIdsForEntityTypeWithRole(array $documentIds, string $entityType, string $role): array
	{
		if ($documentIds === [])
		{
			return [];
		}

		$roleId = $this->convertRoleToInt($role);
		$raws = Internal\MemberTable
			::query()
			->addSelect('DOCUMENT_ID')
			->whereIn('DOCUMENT_ID', $documentIds)
			->where('ENTITY_TYPE', $entityType)
			->where('ROLE', $roleId)
			->setDistinct()
			->fetchAll();

		return array_map(fn($item): int => (int) $item['DOCUMENT_ID'], $raws);
	}

	/**
	 * @param list<int> $documentIds
	 * @return list<int> ids of documents having at least one signer in DONE status
	 */
	public function listDocumentIdsWithSuccessfulSigners(array $documentIds): array
	{
		if ($documentIds === [])
		{
			return [];
		}

		$raws = Internal\MemberTable
			::query()
			->addSelect('DOCUMENT_ID')
			->whereIn('DOCUMENT_ID', $documentIds)
			->where('ROLE', $this->convertRoleToInt(Role::SIGNER))
			->where('SIGNED', MemberStatus::DONE)
			->setDistinct()
			->fetchAll();

		return array_map(static fn(array $item): int => (int)$item['DOCUMENT_ID'], $raws);
	}

	public function listByUidsAndDocumentIdAndStatusesAndRole(
		array $uids,
		int $documentId,
		array $statuses,
		string $role,
		int $limit = 100,
	): Item\MemberCollection
	{
		$roleId = $this->convertRoleToInt($role);
		$memberCollection = Internal\MemberTable::query()
			->addSelect('*')
			->whereIn('UID', $uids)
			->where('DOCUMENT_ID', $documentId)
			->whereIn('SIGNED', $statuses)
			->where('ROLE', $roleId)
			->setLimit($limit)
			->fetchCollection();

		return $this->extractItemCollectionFromModelCollection($memberCollection);
	}
}
