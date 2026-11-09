<?php

namespace Bitrix\Sign\Service\Sign;

use Bitrix\Bizproc\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Connector\MemberDataPicker;
use Bitrix\Sign\File;
use Bitrix\Sign\Integration\Bitrix24\B2eTariff;
use Bitrix\Sign\Integration\CRM;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Operation\Member\GetSignedB2eFileUrlForDownload;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\FileRepository;
use Bitrix\Sign\Repository\MemberNodeRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Result\Operation\Member\GetSignedB2eFileUrlForDownloadResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Main;
use Bitrix\Sign\Item;
use Bitrix\Sign\Service;
use Bitrix\Sign\Service\Sign\Member\CommunicationService;
use Bitrix\Sign\Type;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\Member\ChannelType;
use Bitrix\Sign\Type\Member\ChannelValue;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;

class MemberService
{
	private MemberRepository $memberRepository;
	private DocumentRepository $documentRepository;
	private MemberNodeRepository $memberNodeRepository;
	private Service\UserService $userService;
	private FileRepository $fileRepository;
	private readonly Service\Integration\HumanResources\NodeService $nodeService;
	private Service\Integration\Crm\B2eDocumentService $b2eDocumentService;
	private Service\SignersListService $signersListService;

	private const ALLOWED_ENTITY_TYPES = [
		EntityType::COMPANY,
		EntityType::CONTACT,
		EntityType::ROLE,
		EntityType::USER,
	];
	private const CHANNEL_TYPE_PHONE = Type\Member\ChannelType::PHONE;
	private const CHANNEL_TYPE_EMAIL = Type\Member\ChannelType::EMAIL;
	private const ALLOWED_CHANNEL_TYPES = [
		self::CHANNEL_TYPE_PHONE,
		self::CHANNEL_TYPE_EMAIL,
	];
	private CommunicationService $communicationService;
	private Service\Providers\ProfileProvider $profileProvider;
	private readonly AccessController\AccessControllerFactory $accessControllerFactory;
	public const MAX_REVIEWERS_COUNT = 20;
	private const COMPOSITION_LOCK_NAME_PREFIX = 'sign_document_members_';

	/**
	 * @param \Bitrix\Sign\Repository\MemberRepository|null $memberRepository
	 * @param \Bitrix\Sign\Repository\DocumentRepository|null $documentRepository
	 */
	public function __construct(
		?MemberRepository $memberRepository = null,
		?DocumentRepository $documentRepository = null,
		?MemberNodeRepository $memberNodeRepository = null,
		?FileRepository $fileRepository = null,
		?Service\Integration\Crm\B2eDocumentService $b2eDocumentService = null,
		?Service\UserService $userService = null,
		?Service\SignersListService $signersListService = null,
		?Service\Integration\HumanResources\NodeService $nodeService = null,
	)
	{
		$container = Container::instance();
		$this->memberRepository = $memberRepository ?? $container->getMemberRepository();
		$this->memberNodeRepository = $memberNodeRepository ?? $container->getMemberNodeRepository();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->fileRepository = $fileRepository ?? $container->getFileRepository();
		$this->communicationService = new CommunicationService(Main\Engine\CurrentUser::get()->getId());
		$this->b2eDocumentService = $b2eDocumentService ?? $container->getB2eDocumentService();
		$this->profileProvider = $container->getServiceProfileProvider();
		$this->nodeService = $nodeService ?? $container->getHumanResourcesNodeService();
		$this->accessControllerFactory = $container->getAccessControllerFactory();
		$this->userService = $userService ?? $container->getUserService();
		$this->signersListService = $signersListService ?? $container->getSignersListService();
	}

	public function addForDocument(
		string $documentUid,
		string $entityType,
		int $entityId,
		int $party,
		int $presetId = 0,
		?int $representativeId = null,
		?string $role = null,
		Type\Member\Notification\ReminderType $reminderType = Type\Member\Notification\ReminderType::NONE,
		?int $employeeId = null,
		bool $skipPermissionCheck = false,
		?string $signingMethod = null,
	): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);

		if (!$document)
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_DOCUMENT_NOT_FOUND')),
			);
		}

		if (!in_array($entityType, self::ALLOWED_ENTITY_TYPES, true))
		{
			return (new Main\Result())->addError(
				new Main\Error(
					Loc::getMessage('SIGN_SERVICE_MEMBER_ADD_ERROR'),
					'MEMBER_ENTITY_TYPE_NOT_ALLOWED',
				),
			);
		}

		$member = $this->memberRepository->getByDocumentAndPartAndEntityTypeAndEntityId(
			$document->id,
			$party,
			$entityType,
			$entityId,
		);
		if ($member?->uid !== null)
		{
			return (new Main\Result())->setData(['member' => $member]);
		}

		$addResult = $this->memberRepository->add(new Item\Member(
			documentId: $document->id,
			party: $party,
			entityType: $entityType,
			entityId: $entityId,
			presetId: $presetId,
			role: $role,
			reminder: new Item\Member\Reminder(
				lastSendDate: null,
				plannedNextSendDate: null,
				type: $reminderType,
			),
			employeeId: $employeeId,
			signingMethod: $signingMethod,
		));

		if (!$addResult->isSuccess())
		{
			return (new Main\Result())->addError(
				new Main\Error(
					Loc::getMessage('SIGN_SERVICE_MEMBER_ADD_ERROR'),
					'MEMBER_ADD_ERROR',
				),
			);
		}

		/** @var Item\Member $member */
		$member = $addResult->getData()['member'];
		$this->setDefaultCommunications($member);

		if (
			Type\DocumentScenario::isB2EScenario($document->scenario)
			&& $representativeId
			&& $member->role === Type\Member\Role::ASSIGNEE
		)
		{
			$this->setRepresentativeDefaultCommunications($member, $representativeId);
		}

		//@todo
		if ($presetId === 0 && in_array($member->entityType, [EntityType::COMPANY, EntityType::CONTACT], true))
		{
			$member->presetId = $this->prepareDefaultCrmRequisite($member, $skipPermissionCheck)->getData()['PRESET_ID'];
		}
		$this->memberRepository->update($member);

		return $addResult;
	}

	private function setRepresentativeDefaultCommunications(Item\Member $member, int $representativeId): void
	{
		$fakeMember = new Item\Member(
			entityType: Type\Member\EntityType::USER,
			entityId: $representativeId,
		);
		$this->setDefaultCommunications($fakeMember);
		// should return when channels will be available in b2e document
		$member->channelType = Type\Member\ChannelType::IDLE; // $fakeMember->channelType;
		$member->channelValue = $fakeMember->channelValue;
	}

	/**
	 * The party the signers of a B2E document hold: the last one, or the first one when the employee
	 * started the document. Employees of a synced department join the document as signers and belong
	 * to that party.
	 *
	 * Read from the document itself, so it names the composition that was actually written. A caller
	 * that numbered the parties before the server built the composition, as the wizard does, carries
	 * a number the reordered stages have since shifted.
	 *
	 * @see self::setupB2eMembers() writes the party count this is read from
	 * @see \Bitrix\Sign\Service\Sign\Document\CompanyInitiatedPartyPlanService the renumbering
	 */
	public function getB2eSignerParty(Item\Document $document): ?int
	{
		if ($document->isInitiatedByEmployee())
		{
			return 1;
		}

		return ($document->parties ?? 0) >= 1 ? $document->parties : null;
	}

	public function prepareDepartmentsForSync(
		string $documentUid,
		Item\Hr\EntitySelector\EntityCollection $departments,
	): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document)
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_DOCUMENT_NOT_FOUND')),
			);
		}

		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return (new Main\Result())->addError(
				new Main\Error('Wrong document scenario'),
			);
		}

		if (!$this->nodeService->isCompanyStructureConverted())
		{
			return (new Main\Result())->addError(
				new Main\Error('Company structure is not converted'),
			);
		}

		/** @var Item\Hr\EntitySelector\Entity $department */
		foreach ($departments as $department)
		{
			if (!$this->nodeService->isNodeExists($department->entityId))
			{
				return (new Main\Result())->addError(
					new Main\Error('Department not found'),
				);
			}

			$addResult = $this->memberNodeRepository->addNodeForSync(
				documentId: $document->id,
				nodeId: $department->entityId,
				isFlat: $department->entityType === Type\Hr\EntitySelector\EntityType::FlatDepartment,
			);

			if (!$addResult->isSuccess())
			{
				return $addResult;
			}
		}

		return new Main\Result();
	}

	private function addNodeRelationsForSignersNotInDepartment(MemberCollection $members): Main\Result
	{
		$memberRelations = new Item\Hr\MemberNodeCollection();

		foreach ($members as $member)
		{
			if ($member->entityType !== EntityType::USER || $member->role !== Role::SIGNER)
			{
				return (new Main\Result())->addError(
					new Error('can\'t create relation to non-signer member'),
				);
			}

			$memberRelations->add(
				new Item\Hr\MemberNode(
					documentId: $member->documentId,
					memberId: $member->id,
					nodeSyncId: MemberNodeRepository::NODE_WITHOUT_DEPT,
					userId: $member->entityId,
				),
			);
		}

		if ($memberRelations->count() > 0)
		{
			return $this->memberNodeRepository->addRelationMultiple($memberRelations);
		}

		return new Main\Result();
	}

	/**
	 * @param bool $keepSavedReminderTypes members are recreated here, so a member that comes without a
	 * reminder frequency inherits the one its role already has on the document. Only the parties step
	 * of the wizard asks for it: it edits a document whose settings the user has already seen, while
	 * sending a document composes its members anew and starts from the defaults.
	 *
	 * Writes the member composition of a B2E document: the given members replace the saved ones
	 * whole. The paths that replace a composition whole come here (the signing wizard, the
	 * template send, the copy, the fill and send, the configure activity and its compensation),
	 * and the lock serializes them against each other. Members also change by paths that take
	 * neither this lock nor the state check: \Bitrix\Sign\Operation\Member\SyncDepartmentsPage
	 * and \Bitrix\Sign\Operation\Member\CreateMembers rewrite the composition, and the member
	 * controller adds and removes a single member.
	 *
	 * @see self::findCompositionRefusal() the state a composition may no longer be written in
	 * @see self::writeB2eComposition() what runs under the lock
	 * @see \Bitrix\Sign\Controllers\V1\Document\Member::getSyncMembersLock() sign_sync_members_{uid},
	 *      another lock, over a wider section of the member sync and under a name of its own
	 */
	public function setupB2eMembers(
		string $documentUid,
		Item\MemberCollection $memberCollection,
		int $representativeId,
		bool $skipPermissionCheck = false,
		bool $excludeRejected = true,
		bool $keepSavedReminderTypes = false,
	): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document)
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_DOCUMENT_NOT_FOUND')),
			);
		}

		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return (new Main\Result())->addError(
				new Main\Error('Wrong document scenario'),
			);
		}

		// the state is checked twice: here to spare the validations below, and again under the lock
		$refusal = self::findCompositionRefusal($document);
		if ($refusal !== null)
		{
			return (new Main\Result())->addError($refusal);
		}

		$result = new Main\Result();
		foreach ($memberCollection as $member)
		{
			if ($member->entityId <= 0)
			{
				return $result->addError(new Main\Error('Invalid `entityId` field value'));
			}
		}

		$reviewerCount = $memberCollection
			->filter(fn(Item\Member $member) => $member->role === Type\Member\Role::REVIEWER)
			->count()
		;

		if ($reviewerCount > self::MAX_REVIEWERS_COUNT)
		{
			return (new Main\Result())->addError(
				new Main\Error('Reviewers count can not be greater than '.self::MAX_REVIEWERS_COUNT),
			);
		}

		$maxParty = 1;
		foreach ($memberCollection as $member)
		{
			if ($member->party > $maxParty)
			{
				$maxParty = $member->party;
			}
		}

		// First and second party validation
		$noneSignerMembers = $memberCollection->filter(fn(Item\Member $member) => $member->role !== Type\Member\Role::SIGNER);
		$signers = $memberCollection->filter(fn(Item\Member $member) => $member->role === Type\Member\Role::SIGNER);
		$assignee = $noneSignerMembers->findFirstByRole(Type\Member\Role::ASSIGNEE);

		foreach (range(1, $maxParty - 1) as $party)
		{
			$membersInPartyAmount = $memberCollection->filterByParty($party)->count();
			if ($membersInPartyAmount > 1)
			{
				return $result->addError(new Main\Error("Only last party can has multiple members"));
			}
		}

		if ($assignee === null)
		{
			return $result->addError(new Main\Error("Assignee member is required"));
		}

		if (!in_array($assignee->entityType, [EntityType::COMPANY, EntityType::ROLE]))
		{
			return $result->addError(new Main\Error('`entityType` of assignee must be `company`'));
		}

		foreach ($signers as $member)
		{
			if (!in_array($member?->entityType, EntityType::getEntitySelectorTypes()))
			{
				return $result->addError(
					new Main\Error('All signers `entityType` must be one of known entity types'),
				);
			}
		}

		$signersCount = $signers->filter(
			static fn(Item\Member $signer): bool => $signer->entityType === EntityType::USER,
		)->count();
		$structureNodeMembers = $memberCollection->filter(
			static fn(Item\Member $member): bool => in_array($member->entityType, EntityType::getEntitySelectorTypes()),
		);

		if (!$structureNodeMembers->isEmpty())
		{
			// count with department members
			$entityCollection = Item\Hr\EntitySelector\EntityCollection::fromMemberCollection($signers);
			$signersCountResult = $this->getUniqueSignersCount($entityCollection, $excludeRejected);
			if (!$signersCountResult->isSuccess())
			{
				if (
					$signersCountResult->getErrorCollection()
						->getErrorByCode('SIGN_B2E_MEMBER_SELECTION_EMPTY') === null
				)
				{
					return $signersCountResult;
				}
			}
			else
			{
				$signersCount = (int)$signersCountResult->getData()['count'];
			}
		}

		if (B2eTariff::instance()->isB2eSignersCountRestricted($signersCount))
		{
			return $result->addError(B2eTariff::instance()->getSignersCountAccessError());
		}

		if (!$this->acquireCompositionLock($documentUid))
		{
			return (new Main\Result())->addError(
				new Main\Error(
					message: (string)Loc::getMessage('SIGN_SERVICE_MEMBER_COMPOSITION_IS_BUSY'),
					code: 'SIGN_DOCUMENT_MEMBERS_BUSY',
				),
			);
		}

		try
		{
			return $this->writeB2eComposition(
				$documentUid,
				$memberCollection,
				$assignee,
				$maxParty,
				$representativeId,
				$skipPermissionCheck,
				$keepSavedReminderTypes,
			);
		}
		finally
		{
			$this->releaseCompositionLock($documentUid);
		}
	}

	/**
	 * Reads the saved composition, wipes it and creates the given one in its place. Only the write
	 * runs here: the validations of the composition need no lock, and holding one over them would
	 * keep the document busy for the whole request.
	 *
	 * The document is read anew: the checks above ran before the lock, and the document could have
	 * gone to signing in between.
	 */
	private function writeB2eComposition(
		string $documentUid,
		Item\MemberCollection $memberCollection,
		Item\Member $assignee,
		int $maxParty,
		int $representativeId,
		bool $skipPermissionCheck,
		bool $keepSavedReminderTypes,
	): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document)
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_DOCUMENT_NOT_FOUND')),
			);
		}

		$refusal = self::findCompositionRefusal($document);
		if ($refusal !== null)
		{
			return (new Main\Result())->addError($refusal);
		}

		$savedAssignee = $this->memberRepository
			->listByDocumentIdWithRole($document->id, Type\Member\Role::ASSIGNEE, 1)
			->getFirst()
		;
		if ($savedAssignee !== null)
		{
			$assignee->presetId ??= $savedAssignee->presetId;
		}

		if ($keepSavedReminderTypes)
		{
			$this->applySavedReminderTypes($document->id, $memberCollection);
		}

		$result = $this->cleanByDocumentId($document->id);
		if (!$result->isSuccess())
		{
			return (new Main\Result())->addError(
				new Main\Error("Error trying to delete previous saved members`"),
			);
		}

		Container::instance()
			->getHcmLinkService()
			->fillOneLinkedMembersWithEmployeeId($document, $memberCollection, $representativeId)
		;

		$userMembers = new Item\MemberCollection();
		foreach ($memberCollection as $member)
		{
			if (EntityType::isDepartment($member->entityType))
			{
				continue; // departments will be synced later
			}

			if ($member->entityType === EntityType::USER || $member->entityType === EntityType::ROLE)
			{
				$member->documentId = $document->id;
				$member->channelType = Type\Member\ChannelType::IDLE;
				$member->channelValue = Type\Member\ChannelValue::IDLE_VALUE;
				$userMembers->add($member);
			}
			else
			{
				$result = $this->addForDocument(
					documentUid: $documentUid,
					entityType: $member->entityType,
					entityId: $member->entityId,
					party: $member->party,
					presetId: $member->presetId ?? 0,
					representativeId: $representativeId,
					role: $member->role,
					reminderType: $member->reminder->type,
					employeeId: $member->employeeId,
					skipPermissionCheck: $skipPermissionCheck,
					signingMethod: $member->signingMethod,
				);

				if (!$result->isSuccess())
				{
					$this->memberRepository->deleteAllByDocumentId($document->id);

					return $result;
				}
			}
		}

		$result = $this->memberRepository->addMany($userMembers);
		if (!$result->isSuccess())
		{
			$this->memberRepository->deleteAllByDocumentId($document->id);

			return $result;
		}

		// add relations with the "without department" node
		$selectedMembers = $this->memberRepository->listByDocumentIdWithRole($document->id, Type\Member\Role::SIGNER);
		$result = $this->addNodeRelationsForSignersNotInDepartment($selectedMembers);
		if (!$result->isSuccess())
		{
			$this->cleanByDocumentId($document->id);
			return $result;
		}

		$companyMember = $assignee;
		if (!$document->isTemplated())
		{
			$result = $this->b2eDocumentService->setMyCompany($document, $companyMember->entityId);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		if ($document->parties !== $maxParty)
		{
			$document->parties = $maxParty;
			$result = $this->documentRepository->update($document);
			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return new Main\Result();
	}

	/**
	 * Setting up the parties recreates the members, and the wizard sends no reminder frequency with
	 * them: it is chosen per role on a later step. Without carrying the stored frequency over, a
	 * document that already has one (a copy of a template) loses it. A frequency that did come from
	 * the wizard is an explicit choice and stays untouched.
	 */
	private function applySavedReminderTypes(int $documentId, Item\MemberCollection $memberCollection): void
	{
		$savedReminderTypes = $this->memberRepository->listReminderTypesByDocumentIdIndexedByRole($documentId);
		if ($savedReminderTypes === [])
		{
			return;
		}

		foreach ($memberCollection as $member)
		{
			if (
				$member->role === null
				|| $member->reminder->type !== Type\Member\Notification\ReminderType::NONE
			)
			{
				continue;
			}

			$savedReminderType = $savedReminderTypes[$member->role] ?? null;
			if ($savedReminderType !== null)
			{
				$member->reminder->type = $savedReminderType;
			}
		}
	}

	/**
	 * Points the company member of a B2E document, and the card of the smart process mirroring it,
	 * at the given company. The company member belongs to the company the document is signed from,
	 * so a path that moves the company of the document without rewriting the composition has to
	 * move the member too, or the member and the card keep the company the document no longer has.
	 *
	 * Only the one member is written. Rewriting the composition whole would drop the department
	 * relations of the document, and a caller that was asked for the company has no business
	 * touching the signers.
	 *
	 * The lock and the state check are the ones the composition write itself takes: this is a
	 * writer of the composition like any other, and it is serialized against them by the same name.
	 *
	 * @see self::setupB2eMembers() writes the composition whole and mirrors the same pair
	 * @see self::findCompositionRefusal() the state a composition may no longer be written in
	 */
	public function moveB2eCompanyMember(string $documentUid, int $companyId): Main\Result
	{
		if ($companyId < 1)
		{
			return (new Main\Result())->addError(new Main\Error('Company is empty'));
		}

		if (!$this->acquireCompositionLock($documentUid))
		{
			return (new Main\Result())->addError(
				new Main\Error(
					message: (string)Loc::getMessage('SIGN_SERVICE_MEMBER_COMPOSITION_IS_BUSY'),
					code: 'SIGN_DOCUMENT_MEMBERS_BUSY',
				),
			);
		}

		try
		{
			return $this->writeB2eCompanyMember($documentUid, $companyId);
		}
		finally
		{
			$this->releaseCompositionLock($documentUid);
		}
	}

	/**
	 * The document is read under the lock: the caller checked its state before taking one, and it
	 * could have gone to signing in between.
	 */
	private function writeB2eCompanyMember(string $documentUid, int $companyId): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document)
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_DOCUMENT_NOT_FOUND')),
			);
		}

		$refusal = self::findCompositionRefusal($document);
		if ($refusal !== null)
		{
			return (new Main\Result())->addError($refusal);
		}

		$companyMember = $this->getAssignee($document);

		// the member of a role names no company of its own, and a document without a company
		// member has nothing to move: neither is an error, there is simply nothing to write
		if (
			$companyMember === null
			|| $companyMember->entityType !== EntityType::COMPANY
		)
		{
			return new Main\Result();
		}

		if ($companyMember->entityId !== $companyId)
		{
			// the requisite preset belongs to the company the member pointed at and names nothing
			// under the new one
			$companyMember->entityId = $companyId;
			$companyMember->presetId = 0;

			$updateResult = $this->memberRepository->update($companyMember);
			if (!$updateResult->isSuccess())
			{
				return $updateResult;
			}
		}

		if ($document->isTemplated())
		{
			return new Main\Result();
		}

		return $this->b2eDocumentService->setMyCompany($document, $companyId);
	}

	/**
	 * Why the member composition of the document may no longer be written, if it may not. The
	 * window is the one the document write path itself allows: a document that has gone to signing
	 * keeps the members its invitations were built from, and members created anew would be shown
	 * on the portal while the signing service kept the previous ones.
	 *
	 * A caller needing a narrower window keeps its own check: the configure activity refuses a
	 * document already brought to `ready`, and ConfigureDocument takes an `uploaded` one only.
	 *
	 * @see \Bitrix\Sign\Service\Sign\DocumentService::canBeChanged() the same window
	 */
	private static function findCompositionRefusal(Item\Document $document): ?Main\Error
	{
		if (!in_array($document->status, DocumentStatus::getEnding(), true))
		{
			return null;
		}

		return new Main\Error(
			message: (string)Loc::getMessage('SIGN_SERVICE_MEMBER_COMPOSITION_CANNOT_BE_CHANGED'),
			code: 'SIGN_DOCUMENT_INCORRECT_STATUS',
			customData: [
				'has' => $document->status,
				'expected' => array_values(
					array_diff(DocumentStatus::getAll(), DocumentStatus::getEnding()),
				),
			],
		);
	}

	/**
	 * Zero timeout: a composition another writer is already changing is refused right away instead
	 * of holding the request until it is done.
	 */
	private function acquireCompositionLock(string $documentUid): bool
	{
		return Main\Application::getConnection()->lock(self::COMPOSITION_LOCK_NAME_PREFIX . $documentUid, 0);
	}

	private function releaseCompositionLock(string $documentUid): bool
	{
		return Main\Application::getConnection()->unlock(self::COMPOSITION_LOCK_NAME_PREFIX . $documentUid);
	}

	public function resolveUniqueSignerIds(
		Item\Hr\EntitySelector\EntityCollection $entityCollection,
		bool $excludeRejected = true,
		int $limit = 1000,
	): Main\Result
	{
		if ($limit < 1 || $limit > 1000)
		{
			return (new Main\Result())->addError(
				new Main\Error(
					(string)Loc::getMessage('SIGN_SERVICE_MEMBER_SELECTION_INVALID'),
					'SIGN_B2E_MEMBER_SELECTION_INVALID',
				),
			);
		}

		if ($entityCollection->count() === 0)
		{
			return (new Main\Result())->addError(
				new Main\Error(
					(string)Loc::getMessage('SIGN_SERVICE_MEMBER_SELECTION_EMPTY'),
					'SIGN_B2E_MEMBER_SELECTION_EMPTY',
				),
			);
		}

		if ($entityCollection->count() > $limit)
		{
			return $this->createSignerLimitExceededResult($limit);
		}

		/** @var Item\Hr\EntitySelector\Entity $entity */
		foreach ($entityCollection as $entity)
		{
			if ($entity->entityId < 1 || $entity->entityType === Type\Hr\EntitySelector\EntityType::Unknown)
			{
				return (new Main\Result())->addError(
					new Main\Error(
						(string)Loc::getMessage('SIGN_SERVICE_MEMBER_SELECTION_INVALID'),
						'SIGN_B2E_MEMBER_SELECTION_INVALID',
					),
				);
			}
		}

		$uniqUsers = [];
		$departmentSources = [];
		$signersListIds = [];
		$documentIds = [];

		/** @var Item\Hr\EntitySelector\Entity $entity */
		foreach ($entityCollection as $entity)
		{
			if ($entity->entityType->isUser())
			{
				$uniqUsers[$entity->entityId] = true;
				if (count($uniqUsers) > $limit)
				{
					return $this->createSignerLimitExceededResult($limit);
				}

				continue;
			}

			$sourceKey = $entity->entityType->value . ':' . $entity->entityId;
			if ($entity->entityType->isDepartment())
			{
				$departmentSources[$sourceKey] = $entity;

				continue;
			}
			if ($entity->entityType->isSignersList())
			{
				$signersListIds[$entity->entityId] = true;

				continue;
			}
			if ($entity->entityType->isDocument())
			{
				$documentIds[$entity->entityId] = true;

				continue;
			}

			return (new Main\Result())->addError(
				new Main\Error(
					(string)Loc::getMessage('SIGN_SERVICE_MEMBER_SELECTION_INVALID'),
					'SIGN_B2E_MEMBER_SELECTION_INVALID',
				),
			);
		}

		$sourceReaders = [];
		if ($departmentSources !== [])
		{
			$sourceReaders[] = fn(int $remainingBudget, array $excludedUserIds): array => $this->nodeService
				->listEmployeeUserIdsByEntities(
					array_values($departmentSources),
					$remainingBudget,
					$excludedUserIds,
				)
			;
		}
		if ($signersListIds !== [])
		{
			$sourceReaders[] = fn(int $remainingBudget, array $excludedUserIds): array => $this->signersListService
				->listSignerUserIdsByListIds(
					array_keys($signersListIds),
					$remainingBudget,
					$excludedUserIds,
				)
			;
		}
		if ($documentIds !== [])
		{
			$sourceReaders[] = fn(int $remainingBudget, array $excludedUserIds): array => $this->memberRepository
				->listSignerUserIdsByDocumentIds(
					array_keys($documentIds),
					$remainingBudget,
					$excludedUserIds,
				)
			;
		}
		foreach ($sourceReaders as $sourceReader)
		{
			$remainingBudget = $limit - count($uniqUsers) + 1;
			foreach ($sourceReader($remainingBudget, array_keys($uniqUsers)) as $userId)
			{
				$userId = (int)$userId;
				if ($userId > 0)
				{
					$uniqUsers[$userId] = true;
				}
			}

			if (count($uniqUsers) > $limit)
			{
				return $this->createSignerLimitExceededResult($limit);
			}
		}

		$totalCount = count($uniqUsers);
		if ($totalCount === 0)
		{
			return (new Main\Result())->addError(
				new Main\Error(
					(string)Loc::getMessage('SIGN_SERVICE_MEMBER_SELECTION_EMPTY'),
					'SIGN_B2E_MEMBER_SELECTION_EMPTY',
				),
			);
		}

		$excludedCount = 0;
		if ($excludeRejected)
		{
			foreach ($this->signersListService->listRejectedSignerIdsByUserIds(array_keys($uniqUsers)) as $rejectedUserId)
			{
				$rejectedUserId = (int)$rejectedUserId;
				if (isset($uniqUsers[$rejectedUserId]))
				{
					unset($uniqUsers[$rejectedUserId]);
					$excludedCount++;
				}
			}
		}

		$userIds = array_keys($uniqUsers);

		return (new Main\Result())->setData([
			'userIds' => $userIds,
			'count' => count($userIds),
			'totalCount' => $totalCount,
			'excludedCount' => $excludedCount,
		]);
	}

	public function getUniqueSignersCount(
		Item\Hr\EntitySelector\EntityCollection $entityCollection,
		bool $excludeRejected = true,
	): Main\Result
	{
		return $this->resolveUniqueSignerIds($entityCollection, $excludeRejected);
	}

	private function createSignerLimitExceededResult(int $limit): Main\Result
	{
		return (new Main\Result())->addError(
			new Main\Error(
				(string)Loc::getMessage('SIGN_SERVICE_MEMBER_LIMIT_EXCEEDED', ['#LIMIT#' => $limit]),
				'SIGN_B2E_MEMBER_LIMIT_EXCEEDED',
				['limit' => $limit],
			),
		);
	}

	public function cleanByDocumentId(int $documentId): Main\Result
	{
		$this->memberNodeRepository->resetMemberSyncForDocument($documentId);
		return $this->memberRepository->deleteAllByDocumentId($documentId);
	}

	public function cleanByDocumentUid(string $documentUid): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);
		if (!$document)
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_DOCUMENT_NOT_FOUND')),
			);
		}

		return $this->cleanByDocumentId($document->id);
	}

	/**
	 * @param string $documentUid
	 * @param string $entityType
	 * @param string $entityId
	 * @param int $party
	 *
	 * @return \Bitrix\Main\Result
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function removeFromDocumentAndPart(
		string $documentUid,
		string $entityType,
		int $entityId,
		int $party,
	): Main\Result
	{
		$document = $this->documentRepository->getByUid($documentUid);

		if (!$document)
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_DOCUMENT_NOT_FOUND')),
			);
		}

		$member = $this->memberRepository->getByDocumentIdWithParty(
			$document->id,
			$party,
			$entityType,
			$entityId,
		);

		if (!$member->id)
		{
			return (new Main\Result())->addError(new Main\Error(
				Loc::getMessage('SIGN_SERVICE_MEMBER_NOT_FOUND',
					'MEMBER_NOT_FOUND',
				)),
			);
		}

		$this->memberRepository->deleteById($member->id);

		return new Main\Result();
	}

	/**
	 * @param string $uid
	 *
	 * @return \Bitrix\Main\Result
	 */
	public function remove(string $uid): Main\Result
	{
		$member = $this->memberRepository->getByUid($uid);

		if (!$member->id)
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_NOT_FOUND')),
			);
		}

		$this->memberRepository->deleteById($member->id);
		return (new Main\Result());
	}

	/**
	 * @param string $uid
	 * @param string $channelType
	 * @param string $channelValue
	 *
	 * @return Main\Result
	 */
	public function modifyCommunicationChannel(string $uid, string $channelType, string $channelValue): Main\Result
	{
		if (!in_array($channelType, self::ALLOWED_CHANNEL_TYPES, true))
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_CHANNEL_NOT_ALLOWED')),
			);
		}

		$member = $this->memberRepository->getByUid($uid);

		if (!$member?->id)
		{
			return (new Main\Result())->addError(
				new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_NOT_FOUND')),
			);
		}

		$member->channelValue = $channelValue;
		$member->channelType = $channelType;

		$result = $this->validateMemberChannelLicenceRestrictions($member);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $this->memberRepository->update($member);
	}

	/**
	 * @param string $uid
	 *
	 * @return \Bitrix\Sign\Item\Member|null
	 */
	public function getByUid(string $uid): ?Item\Member
	{
		return $this->memberRepository->getByUid($uid);
	}

	/**
	 * @param int $memberId
	 *
	 * @return \Bitrix\Sign\Item\Member|null
	 */
	public function getById(int $memberId): ?Item\Member
	{
		return $this->memberRepository->getById($memberId);
	}

	public function listByDocumentId(int $documentId): Item\MemberCollection
	{
		return $this->memberRepository->listByDocumentId($documentId);
	}

	private function prepareDefaultCrmRequisite(
		Item\Member $member,
		bool $skipPermissionCheck = false,
	): Main\ORM\Data\AddResult|Main\Result
	{
		$document = $this->documentRepository->getById($member->documentId);
		if ($document === null)
		{
			return (new Main\Result())->addError(new Main\Error('Linked with member document doesnt exist'));
		}
		$accessController = $this->accessControllerFactory->createForCurrentUser();

		if (
			$document->isTemplated()
			&& $accessController->checkByItem(ActionDictionary::ACTION_B2E_TEMPLATE_EDIT, $document)
		)
		{
			// it already validated in current if condition
			$skipPermissionCheck = true;
		}

		$presetId = $member->role === Type\Member\Role::ASSIGNEE
			? CRM::getMyDefaultPresetId($document->entityId, $member->entityId, checkCrmPermissions: !$skipPermissionCheck)
			: CRM::getOtherSidePresetId($document->entityId)
		;

		if (!$presetId && $member->entityId)
		{
			return CRM::createDefaultRequisite(
				$document->entityId,
				$member->entityId,
				\CCrmOwnerType::ResolveID($member->entityType),
			);
		}
		$addResult = new Main\ORM\Data\AddResult();
		$addResult->setId($presetId);
		$addResult->setData(['PRESET_ID' => $presetId]);

		return $addResult;
	}

	/**
	 * @param \Bitrix\Sign\Item\Member $member
	 *
	 * @return array
	 */
	public function getCommunications(Item\Member $member): array
	{
		$connector = (new \Bitrix\Sign\Connector\MemberConnectorFactory())->create($member);

		$values = $connector->fetchFields();
		$communications = [];
		foreach ($values as $field)
		{
			if ($field->name === 'FM')
			{
				foreach ($field->data as $type => $multipleField)
				{
					$communicationTypes = [self::CHANNEL_TYPE_PHONE, self::CHANNEL_TYPE_EMAIL,];
					if (in_array($type, $communicationTypes))
					{
						foreach ($multipleField as $communication)
						{
							if (!isset($communications[$type]))
							{
								$communications[$type] = [];
							}
							$communications[$type][] = $communication;
						}
					}
				}
			}
		}

		return $communications;
	}

	public function saveStampFile(int $fileId, Item\Member $member): Main\Result
	{
		if ($member->entityType !== EntityType::COMPANY)
		{
			return (new Main\Result())->addError(new Main\Error(
				Loc::getMessage('SIGN_SERVICE_MEMBER_STAMP_MEMBER_IS_NOT_COMPANY'),
			));
		}
		if ($member->documentId === null)
		{
			return (new Main\Result())->addError(new Main\Error(
				Loc::getMessage('SIGN_SERVICE_MEMBER_DOCUMENT_NOT_FOUND'),
			));
		}

		$stamp = new File($fileId);

		if (!$stamp->isExist())
		{
			return (new Main\Result())->addError(new Main\Error(
				Loc::getMessage('SIGN_SERVICE_MEMBER_STAMP_FILE_NOT_FOUND'),
			));
		}
		$stamp->setModule('crm');
		$savedStampFileId = $stamp->save();
		if ($savedStampFileId === null)
		{
			return (new Main\Result())->addError(new Main\Error(
				Loc::getMessage('SIGN_SERVICE_MEMBER_STAMP_FILE_NOT_SAVED'),
			));
		}

		// The stamp of the company is stored in its requisite, and CRM asks for the right to change that
		// company. A caller without it is refused here, and the refusal is an ordinary answer of this
		// action rather than a breakdown: the document is left exactly as it was, and the call counts as
		// done only when both the requisite and the member carry the new stamp.
		$saveResult = CRM::saveCompanyStamp($member->entityId, $stamp);
		if (!$saveResult->isSuccess())
		{
			// The file is left alone: it is not this action's to delete - the uploader that sent it holds
			// it and collects it on its own - and the same id can already be the stamp the company keeps,
			// in which case erasing it would break a reference that is in use.
			return (new Main\Result())->addError(self::getStampRequisiteError($saveResult));
		}

		$member->stampFileId = $savedStampFileId;

		$updateResult = $this->memberRepository->update($member);
		if (!$updateResult->isSuccess())
		{
			return (new Main\Result())->addErrors($updateResult->getErrors());
		}

		return (new Main\Result())->setData(['fileId' => $stamp->getId()]);
	}

	/**
	 * The refusal of CRM told apart from everything else the requisite can answer: the first one is
	 * about the right to change the company and is worth explaining to the caller, the rest is a
	 * failure of the write itself.
	 *
	 * The code is compared as a literal on purpose: it is the value of
	 * \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED, and reading the constant would need the module
	 * loaded on a path that is reached when it is not.
	 */
	private static function getStampRequisiteError(Main\Result $requisiteResult): Main\Error
	{
		foreach ($requisiteResult->getErrors() as $error)
		{
			if ($error->getCode() === 'ACCESS_DENIED')
			{
				return new Main\Error(
					Loc::getMessage('SIGN_SERVICE_MEMBER_STAMP_ACCESS_DENIED'),
					'ACCESS_DENIED',
				);
			}
		}

		return new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_STAMP_REQUISITE_NOT_SAVED'));
	}

	public function getStampFileFromMemberOrEntity(Item\Member $member): ?Item\Fs\File
	{
		$stampFileId = $member->stampFileId;
		if ($stampFileId === null)
		{
			return $this->getMemberStampFromEntity($member);
		}

		return $this->fileRepository->getById($stampFileId);
	}

	public function getLinkForSigning(Item\Member $member): \Bitrix\Main\Result
	{
		if (!in_array($member->role, [
			Type\Member\Role::ASSIGNEE,
			Type\Member\Role::SIGNER,
			Type\Member\Role::EDITOR,
			Type\Member\Role::REVIEWER,
		], true))
		{
			return (new Main\Result())->addError(new Main\Error('Access denied', 'ACCESS_DENIED'));
		}

		$document = $this->documentRepository->getById($member->documentId);

		if (!Type\DocumentScenario::isB2EScenario($document->scenario))
		{
			return (new Main\Result())->addError(new Main\Error('Access denied', 'ACCESS_DENIED'));
		}

		$result = Service\Container::instance()
			->getApiService()
			->post('v1/b2e.member.getlinkforsigning/' . $document->uid . '/' . $member->uid . '/')
		;

		return (new Main\Result())
			->addErrors($result->getErrors())
			->setData([
				'uri' => $result->getData()['uri'] ?? null,
			])
		;
	}

	public function getLinkForSignedFile(Item\Member $member): \Bitrix\Main\Result
	{
		if (!in_array($member->role, [
			Type\Member\Role::SIGNER,
		], true))
		{
			return (new Main\Result())->addError(new Main\Error('Access denied', 'ACCESS_DENIED'));
		}

		if ($member->status !== Type\MemberStatus::DONE)
		{
			return (new Main\Result())->addError(new Main\Error('Access denied', 'ACCESS_DENIED'));
		}

		$document = $this->documentRepository->getById($member->documentId);

		if (!$document)
		{
			return (new Main\Result())->addError(new Main\Error('no such document'));
		}

		$downloadUrl = null;

		if (DocumentScenario::isB2EScenario($document->scenario))
		{
			$result = (new GetSignedB2eFileUrlForDownload($member, $document))->launch();
			if ($result instanceof GetSignedB2eFileUrlForDownloadResult)
			{
				$downloadUrl = $result->url;
			}
		}
		else
		{
			$operation = new \Bitrix\Sign\Operation\GetSignedFilePdfUrl(
				$document->uid,
				$member->uid,
			);
			if ($operation->launch()->isSuccess())
			{
				$downloadUrl = $operation->url;
			}
		}

		return $downloadUrl
			? (new Main\Result())->setData(['url' => $downloadUrl])
			: (new Main\Result())->addError(new Error('not ready for download'))
		;
	}

	public function getUserIdForMember(Item\Member $member, ?Item\Document $document = null): ?int
	{
		if ($member->entityType === EntityType::COMPANY && $document?->id !== $member->documentId)
		{
			$document = $this->documentRepository->getById($member->documentId);
		}

		return match ($member->entityType)
		{
			EntityType::COMPANY => $document->representativeId,
			EntityType::USER => $member->entityId,
			default => null,
		};
	}

	/**
	 * @return list<int>
	 */
	public function getUserIdsForMembers(Item\MemberCollection $memberCollection, ?Item\Document $document = null): array
	{
		$result = [];
		foreach ($memberCollection as $member)
		{
			$userId = $this->getUserIdForMember($member, $document);
			if (is_int($userId))
			{
				$result[] = $userId;
			}
		}

		return $result;
	}

	public function getUserIdsMapForMembers(Item\MemberCollection $memberCollection, ?Item\Document $document = null): array
	{
		$result = [];
		foreach ($memberCollection as $member)
		{
			$userId = $this->getUserIdForMember($member, $document);
			if (is_int($userId))
			{
				$result[$member->id] = $userId;
			}
		}

		return $result;
	}

	public function getCountForCurrentUserAction(int $userId): int
	{
		if ($userId < 1)
		{
			return 0;
		}

		return $this->memberRepository->getCountForCurrentUserAction($userId);
	}

	/**
	 * @return list<int>
	 */
	public function getUserIdsByDocument(Item\Document $document): array
	{
		$memberUserIds = $this->memberRepository->listUserIdsByDocumentId($document->id);
		if ($document->representativeId)
		{
			$memberUserIds[] = $document->representativeId;
		}

		return array_unique($memberUserIds);
	}

	public function getCurrentParticipantFromCompanySide(Item\Document $document): ?Item\Member
	{
		return $this->memberRepository
			->listByDocumentIdExcludeRole($document->id, Type\Member\Role::SIGNER)
			->filterByStatus(Type\MemberStatus::getReadyForSigning())
			->getFirst()
		;
	}

	public function getAssignee(Item\Document $document): ?Item\Member
	{
		return $this->memberRepository
			->listByDocumentIdWithRole($document->id, Type\Member\Role::ASSIGNEE, 1)
			->getFirst()
		;
	}

	public function getSigner(Item\Document $document): ?Item\Member
	{
		return $this->memberRepository
			->listByDocumentIdWithRole($document->id, Type\Member\Role::SIGNER, 1)
			->getFirst()
		;
	}

	public function listByDocumentIdWithRole(Item\Document $document, string $role): Item\MemberCollection
	{
		if (!in_array($role, Type\Member\Role::getAll()))
		{
			return new Item\MemberCollection();
		}

		return $this->memberRepository->listByDocumentIdWithRole($document->id, $role, 100);
	}

	public function getMemberRepresentedName(Item\Member $member): ?string
	{
		$userId = $this->getUserIdForMember($member);

		if ($userId)
		{
			return $this->getUserRepresentedName($userId);
		}

		return MemberDataPicker::createByMember($member)
			->getName()
		;
	}

	public function getMemberRepresentedNameForTriggerPayload(
		Item\Member $member,
		?Item\Document $document = null,
		?int $userId = null,
	): ?string
	{
		$userId ??= $this->getUserIdForMember($member, $document);

		if ($userId)
		{
			return $this->getUserRepresentedName($userId);
		}

		return MemberDataPicker::createByMember($member)
			->getName()
		;
	}

	public function getUserRepresentedName(int $userId): string
	{
		$name = $this->profileProvider->loadFieldData($userId, 'UF_LEGAL_NAME')->value;
		$lastName = $this->profileProvider->loadFieldData($userId, 'UF_LEGAL_LAST_NAME')->value;
		$secondName = $this->profileProvider->loadFieldData($userId, 'UF_LEGAL_PATRONYMIC_NAME')->value;

		return $this->profileProvider->getFormattedName($name, $lastName, $secondName);
	}

	private function getMemberStampFromEntity(Item\Member $member): ?Item\Fs\File
	{
		if ($member->entityType !== EntityType::COMPANY || $member->entityId === null)
		{
			return null;
		}
		$oldFileEntity = CRM::getCompanyStamp($member->entityId);
		$fileId = $oldFileEntity?->getId();
		if ($fileId === null)
		{
			return null;
		}

		return $this->fileRepository->getById($fileId);
	}

	private function setDefaultCommunications(Item\Member $member): void
	{
		$communications = MemberDataPicker::createByMember($member)
			->getCommunications()
		;

		$isSmsAllowed = \Bitrix\Sign\Restriction::isSmsAllowed();
		if ($isSmsAllowed && isset($communications[self::CHANNEL_TYPE_PHONE]))
		{
			$member->channelType = self::CHANNEL_TYPE_PHONE;
			$member->channelValue = $communications[self::CHANNEL_TYPE_PHONE][0] ?? null;
			return;
		}

		if (isset($communications[self::CHANNEL_TYPE_EMAIL]))
		{
			$member->channelType = self::CHANNEL_TYPE_EMAIL;
			$member->channelValue = $communications[self::CHANNEL_TYPE_EMAIL][0] ?? null;
		}
	}

	private function validateMemberChannelLicenceRestrictions(Item\Member $member): Main\Result
	{
		return $this->communicationService->validateMemberChannelLicenceRestrictions($member);
	}

	public function getMemberOfDocument(Item\Document $document, string $memberUid): ?Item\Member
	{
		if (empty($memberUid))
		{
			return null;
		}

		$member = $this->memberRepository->getByUid($memberUid);

		return ($member && ($member->documentId === $document->id)) ? $member : null;
	}

	public function isUserLinksWithMember(Item\Member $member, Item\Document $document, int $userId): bool
	{
		return $this->getUserIdForMember($member, $document) === $userId;
	}

	public function countUnfinishedSigners(int $documentId): int
	{
		return $this->memberRepository
			->countMembersByDocumentIdAndRoleAndStatus($documentId, MemberStatus::getStatusesNotFinished())
		;
	}

	public function countSuccessfulSigners(int $documentId): int
	{
		return $this->memberRepository->countMembersByDocumentIdAndRoleAndStatus($documentId, [MemberStatus::DONE]);
	}

	public function countReadySigners(int $documentId): int
	{
		return $this->memberRepository->countMembersByDocumentIdAndRoleAndStatus(
			$documentId,
			[MemberStatus::READY, MemberStatus::STOPPABLE_READY],
		);
	}

	public function countWaitingSigners(int $documentId): int
	{
		return $this->memberRepository->countMembersByDocumentIdAndRoleAndStatus($documentId, [MemberStatus::WAIT]);
	}

	public function setProfileProviderCache(?Service\Cache\Memory\Sign\UserCache $userCache): static
	{
		$this->profileProvider->setCache($userCache);

		return $this;
	}

	/**
	 * Skip invitation if initiator signs immediately after document creation.
	 *
	 * @todo if part > 1, check userId for reviewer/editor parties
	 *
	 * @param Item\Member $member
	 * @param Item\Document $document
	 *
	 * @return bool
	 */
	public function skipChatInvitationForMember(Item\Member $member, Item\Document $document): bool
	{
		// crm 24.800.0 dependency
		if (
			! Main\Loader::includeModule('crm')
			|| !defined('\Bitrix\Crm\Timeline\SignDocument\Channel::TYPE_B24')
		)
		{
			return false;
		}

		return
			$member->role === Role::ASSIGNEE
			&& $member->party === 1
			&& $this->getUserIdForMember($member) === $document->createdById
		;
	}

	public function isDocumentHasSuccessfulSigners(int $documentId): bool
	{
		return !$this->memberRepository->listByDocumentIdAndRoleAndStatus(
			$documentId,
			Type\Member\Role::SIGNER, 1,
			[MemberStatus::DONE]
		)->isEmpty();
	}

	/**
	 * @param list<int> $documentIds
	 * @return list<int> ids of documents having at least one signer in DONE status
	 */
	public function listDocumentIdsWithSuccessfulSigners(array $documentIds): array
	{
		return $this->memberRepository->listDocumentIdsWithSuccessfulSigners($documentIds);
	}

	public function getByDocumentIdWithRole(int $documentId, string $role): ?Item\Member
	{
		return $this->memberRepository->getByDocumentIdWithRole($documentId, $role);
	}

	public function filterFiredSigners(MemberCollection $signers): MemberCollection
	{
		$filteredCollection = new MemberCollection();

		$userIds = $this->getUserIdsForMembers($signers);
		$users = $this->userService->listByIds($userIds);

		foreach ($signers as $member)
		{
			$userId = $this->getUserIdForMember($member);

			if (!$userId)
			{
				continue;
			}

			$user = $users->getByIdMap($userId);

			if (!$user || !$user->isActive)
			{
				continue;
			}

			$filteredCollection->add($member);
		}

		return $filteredCollection;
	}

	public function getUniqueUserIdsByDocuments(Item\DocumentCollection $documents): array
	{
		$representatives = [];
		$documentIds = [];
		foreach ($documents as $document)
		{
			$representatives[] = $document->representativeId;
			$documentIds[] = $document->id;
		}

		$userIds = $this->memberRepository->listUniqueUserIdsByDocumentIds($documentIds);

		return array_unique(array_merge($userIds, $representatives));
	}

	public function makeAssigneeByDocumentAndEntityId(Document $document, int $entityId): Member
	{
		return new Member(
			documentId: $document->id,
			channelType: ChannelType::IDLE,
			channelValue: ChannelValue::IDLE_VALUE,
			entityType: EntityType::COMPANY,
			entityId: $entityId,
			role: Role::ASSIGNEE,
		);
	}

	public function makeSignerByDocumentAndEntityId(Document $document, int $entityId): Member
	{
		return new Member(
			documentId: $document->id,
			channelType: ChannelType::IDLE,
			channelValue: ChannelValue::IDLE_VALUE,
			entityType: EntityType::USER,
			entityId: $entityId,
			role: Role::SIGNER,
		);
	}

	public function isUserMemberOrInitiatorWithDoneStatus(int $userId): bool
	{
		return $this->memberRepository->isUserMemberOrInitiatorWithDoneStatus($userId);
	}

	public function listMembersWithResultFileMissing(): Item\MemberCollection
	{
		return $this->memberRepository->listDoneSignersWithoutResultFile();
	}

	public function listAssigneesWithResultFileMissing(): Item\MemberCollection
	{
		return $this->memberRepository->listDoneAssigneesWithoutResultFile();
	}

	/**
	 *@param int[] $documentIds
	 *@return int[]
	 */
	public function getDocumentIdsForEntityTypeWithRole(array $documentIds, string $entityType, string $role): array
	{
		if (
			!in_array($entityType, EntityType::getAll(), true) ||
			!in_array($role, Role::getAll(), true)
		)
		{
			return [];
		}

		return $this->memberRepository->getDocumentIdsForEntityTypeWithRole($documentIds, $entityType, $role);
	}
}
