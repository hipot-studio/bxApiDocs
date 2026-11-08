<?php

namespace Bitrix\Sign\Controllers\V1\Document;

use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Access\DocumentAnnulPermission;
use Bitrix\Sign\Access\Service\SelectorSourceAccessService;
use Bitrix\Sign\Attribute;
use Bitrix\Sign\Debug\Logger;
use Bitrix\Sign\FeatureResolver;
use Bitrix\Sign\Helper\IterationHelper;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Hr\EntitySelector\EntityCollection;
use Bitrix\Sign\Item\Hr\NodeSync;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Operation\Member\GetMembersFromUserPartyEntities;
use Bitrix\Sign\Operation\Member\SyncDepartmentsPage;
use Bitrix\Sign\Operation\Member\Validation\ValidateEntitySelectorMembers;
use Bitrix\Sign\Result\Operation\Member\ValidateEntitySelectorMembersResult;
use Bitrix\Sign\Service;
use Bitrix\Sign\Type\Access\AccessibleItemType;
use Bitrix\Sign\Type\Access\ItemIdentifierType;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\Member\EntityType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\Member\SigningMethod;
use Bitrix\Sign\Type\MemberStatus;
use Bitrix\Sign\Type\ProviderCode;
use Bitrix\Sign\Ui\Member\Stage;

class Member extends \Bitrix\Sign\Engine\Controller
{
	/**
	 * Server-side cap on the number of member records a single batch annulment
	 * call may touch. Guards against unbounded "select all" grid mass actions.
	 */
	private const MAX_ANNUL_BATCH_COUNT = 100;

	/** Reading the stage of a member is available to whoever may read the document that owns it. */
	private const LOAD_STAGE_ACCESS_ACTIONS = [
		ActionDictionary::ACTION_B2E_DOCUMENT_READ,
		ActionDictionary::ACTION_DOCUMENT_READ,
	];

	private Service\Sign\MemberService $memberService;
	private Service\Sign\DocumentService $documentService;

	public function __construct(Request $request = null)
	{
		parent::__construct($request);
		$this->memberService = $this->container->getMemberService();
		$this->documentService = $this->container->getDocumentService();
	}

	/**
	 * @param string $documentUid
	 *
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentUid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentUid',
		)
	)]
	public function loadAction(string $documentUid): array
	{
		$document = Service\Container::instance()
			->getDocumentRepository()
			->getByUid($documentUid)
		;

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_MEMBER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		$members = Service\Container::instance()
			->getMemberRepository()
			->listByDocumentId($document->id)
		;

		if (!$document->isTemplated())
		{
			$members = $members->filter(
				static fn(\Bitrix\Sign\Item\Member $member): bool => $member->entityType !== EntityType::ROLE,
			);
		}

		$result = [];
		$isCrmModuleIncluded = Loader::includeModule('crm');
		foreach ($members as $member)
		{
			$entityTypeId = null;
			if ($isCrmModuleIncluded)
			{
				$entityTypeId = match ($member->entityType)
				{
					EntityType::CONTACT => \CCrmOwnerType::Contact,
					EntityType::COMPANY => \CCrmOwnerType::Company,
					default => null,
				};
			}

			$result[] = [
				'uid' => $member->uid,
				'entityId' => $member->entityId,
				'entityType' => $member->entityType,
				'entityTypeId' => $entityTypeId,
				'presetId' => $member->presetId,
				'party' => $member->party,
				'role' => $member->role,
				// The wizard preselects the stored signing method when the setup is reopened.
				'signingMethod' => $member->signingMethod,
				'reminderType' => $member->reminder->type->value,
			];
		}

		return $result;
	}

	/**
	 * @param string $documentUid
	 * @param string $entityType
	 * @param int $entityId
	 * @param int $party
	 * @param int $presetId
	 *
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentUid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentUid',
		),
	)]
	public function addAction(
		string $documentUid,
		string $entityType,
		int $entityId,
		int $party,
		int $presetId = 0,
	): array
	{
		$addResult = $this->memberService->addForDocument($documentUid, $entityType, $entityId, $party, $presetId);

		if (!$addResult->isSuccess())
		{
			$this->addErrors($addResult->getErrors());

			return [];
		}

		return [
			'uid' => $addResult->getData()['member']->uid,
		];
	}

	/**
	 * @param string $uid
	 *
	 * @return array
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'uid',
			ItemIdentifierType::MEMBER_UID,
		),
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'uid',
			ItemIdentifierType::MEMBER_UID,
		)
	)]
	public function removeAction(string $uid)
	{
		$removeResult = $this->memberService->remove($uid);

		if (!$removeResult->isSuccess())
		{
			$this->addErrors($removeResult->getErrors());

			return [];
		}

		return [];
	}

	/**
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentUid',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentUid',
		),
	)]
	public function removeByPartAction(string $documentUid, string $entityType, int $entityId, int $party): array
	{
		$removeResult = $this->memberService->removeFromDocumentAndPart($documentUid, $entityType, $entityId, $party);

		if (!$removeResult->isSuccess())
		{
			$this->addErrors($removeResult->getErrors());

			return [];
		}

		return [];
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'documentUid',
	)]
	public function getDepartmentsForDocumentAction(
		string $documentUid,
		int $page = 1,
		int $pageSize = 19,
	): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			$this->addError(new Error('Module humanresources is not available'));

			return [];
		}

		$document = Service\Container::instance()->getDocumentService()->getByUid($documentUid);

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_MEMBER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		[$limit, $offset] = \Bitrix\Sign\Util\Query\Db\Paginator::getLimitAndOffset($pageSize, $page);

		$documentNodes = Service\Container::instance()
			->getMemberNodeRepository()
			->getNodesForDocument($document->id, $limit, $offset)
		;

		$departments = [];
		/** @var NodeSync $node */
		foreach ($documentNodes as $node)
		{
			$nodeInfo = \Bitrix\HumanResources\Service\Container::instance()->getNodeService()->getNodeInformation($node->nodeId);

			if (!$nodeInfo)
			{
				$this->addError(new Error('node info error'));

				return [];
			}

			$departments[] = [
				'id' => $nodeInfo->id,
				'name' => $node->isFlat
					? Loc::getMessage('SIGN_CONTROLLER_MEMBER_FLAT_DEPARTMENT', [
						'#DEPARTMENT_NAME#' => $nodeInfo->name,
					])
					: $nodeInfo->name
				,
			];
		}

		return [
			'departments' => $departments,
		];
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'documentUid',
	)]
	public function getMembersForDocumentAction(
		string $documentUid,
		string $role = Role::SIGNER,
		int $page = 1,
		int $pageSize = 20,
	): array
	{
		if (!Role::isValid($role))
		{
			$this->addError(new Error('Invalid role'));

			return [];
		}

		$document = Service\Container::instance()->getDocumentService()->getByUid($documentUid);

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_MEMBER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		[$limit, $offset] = \Bitrix\Sign\Util\Query\Db\Paginator::getLimitAndOffset($pageSize, $page);

		$memberCollection = Service\Container::instance()
			->getMemberRepository()
			->listByDocumentIdWithRole($document->id, $role, $limit, $offset)
			->toArray()
		;

		$members = [];
		foreach ($memberCollection as $member)
		{
			$avatar = Service\Container::instance()->getSignMemberUserService()->getAvatarByMemberUid($member->uid);
			$userId = Service\Container::instance()->getMemberService()->getUserIdForMember($member, $document);
			$name = $member->name;

			if ($member->entityType === EntityType::ROLE)
			{
				$name = Service\Container::instance()
					->getHumanResourcesStructureNodeService()
					->getRoleTitleById($member->entityId)
				;
			}
			$members[] = [
				'memberId' => $member->id,
				'userId' => $userId,
				'name' => (string)$name,
				'avatar' => $avatar?->getBase64Content(),
				'profileUrl' => $userId ? '/company/personal/user/' . $userId . '/' : '',
			];
		}

		return [
			'members' => $members,
		];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_SIGNERS_LIST_EDIT),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_SIGNERS_LIST_REFUSED_EDIT),
	)]
	public function getUniqSignersCountAction(
		array $members,
		Logger $logger,
		SelectorSourceAccessService $selectorSourceAccessService,
		bool $excludeRejected = true,
	): array
	{
		$entityCollection = new EntityCollection();
		foreach ($members as $member)
		{
			if (!isset($member['entityType'], $member['entityId']))
			{
				$this->addError(new Error('Invalid member data'));

				return [];
			}

			$entityCollection->add(
				\Bitrix\Sign\Item\Hr\EntitySelector\Entity::createFromStrings(
					entityId: $member['entityId'],
					entityType: $member['entityType'],
				),
			);
		}

		// Expansion sources come from the request, so the right to read each source must be
		// checked before its composition is counted.
		$sourceAccessResult = $selectorSourceAccessService->checkEntityCollection($entityCollection);
		if (!$sourceAccessResult->isSuccess())
		{
			$this->addAccessDeniedError();

			return [];
		}

		$result = $this->memberService->getUniqueSignersCount($entityCollection, $excludeRejected);

		if (!$result->isSuccess())
		{
			$logger->error($result->getError()?->getMessage() ?? 'unknown error', ['errors' => $result->getErrors()]);
			$this->addErrors($result->getErrors());

			return [];
		}

		$data = $result->getData();

		return [
			'count' => $data['count'],
			'totalCount' => $data['totalCount'],
			'excludedCount' => $data['excludedCount'],
		];
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'documentUid',
	)]
	public function getUniqSignersCountForDocumentAction(
		string $documentUid,
		bool $excludeRejected = true,
	): array
	{
		$document = Service\Container::instance()->getDocumentService()->getByUid($documentUid);

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_MEMBER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		$memberCollection = Service\Container::instance()
			->getMemberRepository()
			->listByDocumentIdWithRole($document->id, Role::SIGNER)
		;

		$entityCollection = EntityCollection::fromMemberCollection($memberCollection);

		$result = $this->memberService->getUniqueSignersCount($entityCollection, $excludeRejected);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$data = $result->getData();

		return [
			'count' => $data['count'],
			'totalCount' => $data['totalCount'],
			'excludedCount' => $data['excludedCount'],
		];
	}

	#[Attribute\ActionAccess(
		ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
		itemType: AccessibleItemType::DOCUMENT,
		itemIdOrUidRequestKey: 'documentUid',
	)]
	public function syncB2eMembersWithDepartmentsAction(
		string $documentUid,
		int $currentParty,
		Logger $logger,
		bool $excludeRejected = true,
	): array
	{
		if (!$this->getSyncMembersLock($documentUid))
		{
			return ['syncFinished' => false];
		}

		if (!Main\Loader::includeModule('humanresources'))
		{
			$this->addError(new Main\Error('Module humanresources is not available'));

			return [];
		}

		$document = $this->documentService->getByUid($documentUid);

		if (!$document)
		{
			$this->addError(new Main\Error(Loc::getMessage('SIGN_SERVICE_MEMBER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		$nodeMemberService = \Bitrix\HumanResources\Service\Container::instance()->getNodeMemberService();
		$result = (new SyncDepartmentsPage($document, $currentParty, $nodeMemberService, $excludeRejected))->launch();

		if (!$result->isSuccess())
		{
			$logger->error($result->getError()?->getMessage() ?? 'unknown error', ['errors' => $result->getErrors()]);
			$this->addErrorByMessage('Error while syncing departments');

			return [];
		}

		if ($result->isSuccess())
		{
			return [
				'syncFinished' => $result->getData()['syncFinished'] ?? false,
			];
		}

		$this->releaseSyncMembersLock($documentUid);

		return [
			'syncFinished' => true,
		];
	}

	/**
	 * @param string $documentUid
	 * @param int $representativeId
	 * @param array{entityId: string, entityType: string, party: int, role: string} $members Members data [[entityId, entityType, party], ...]
	 * @return array
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_DOCUMENT_EDIT, AccessibleItemType::DOCUMENT, 'documentUid'),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT, AccessibleItemType::DOCUMENT, 'documentUid')
	)]
	public function setupB2ePartiesAction(
		string $documentUid,
		int $representativeId,
		array $members,
		SelectorSourceAccessService $selectorSourceAccessService,
		bool $excludeRejected = true,
	): array
	{
		if ($representativeId <= 0)
		{
			$this->addError(
				new Error("Invalid `representativeId` value"),
			);

			return [];
		}

		$document = $this->documentService->getByUid($documentUid);

		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_MEMBER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		$result = (new ValidateEntitySelectorMembers($members))->launch();
		if (!$result instanceof ValidateEntitySelectorMembersResult)
		{
			$this->addErrorsFromResult($result);

			return [];
		}

		// Expansion sources come from the request, so the right to read each source must be
		// checked before its composition is revealed.
		$sourceAccessResult = $selectorSourceAccessService->checkSelectorEntities($result->entities);
		if (!$sourceAccessResult->isSuccess())
		{
			$this->addAccessDeniedError();

			return [];
		}

		$result = (new GetMembersFromUserPartyEntities($result->entities, excludeRejectedSigners: $excludeRejected))->launch();
		$memberCollection = $result->members;
		$departmentEntities = $result->departments;
		$assigneeEntityType = $result->assigneeEntityType;
		$memberCollection = $this->fillSigningMethods(
			$memberCollection,
			$members,
			$assigneeEntityType,
			$document,
		);
		if ($this->getErrors())
		{
			return [];
		}

		if (!$document->isTemplated())
		{
			$memberCollection = $memberCollection->filter(
				static fn(\Bitrix\Sign\Item\Member $member): bool => $member->entityType !== EntityType::ROLE,
			);
		}

		$result = $this->memberService->setupB2eMembers(
			$documentUid,
			$memberCollection,
			$representativeId,
			excludeRejected: $excludeRejected,
			keepSavedReminderTypes: true,
		);
		if (!$result->isSuccess())
		{
			if ($result->getErrorCollection()->getErrorByCode('COMPANY_DOESNT_EXIST'))
			{
				$this->addErrorByMessage(Main\Localization\Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_DOCUMENT_TEMPLATE_ERROR_COMPANY_DOESNT_EXIST'));

				return [];
			}

			$this->addErrors($result->getErrors());

			return [];
		}

		if ($departmentEntities->count() && Loader::includeModule('humanresources'))
		{
			$result = $this->memberService->prepareDepartmentsForSync($documentUid, $departmentEntities);
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());

				return [];
			}
		}

		$result = $this->documentService->modifyRepresentativeId($documentUid, $representativeId, $assigneeEntityType);
		if (!$result->isSuccess())
		{
			// Revert adding members
			$this->memberService->cleanByDocumentUid($documentUid);
			$this->addErrors($result->getErrors());

			return [];
		}

		return [];
	}

	private function fillSigningMethods(
		\Bitrix\Sign\Item\MemberCollection $memberCollection,
		array $members,
		string $assigneeEntityType,
		\Bitrix\Sign\Item\Document $document,
	): \Bitrix\Sign\Item\MemberCollection
	{
		foreach ($members as $memberData)
		{
			if (($memberData['role'] ?? null) !== Role::ASSIGNEE)
			{
				continue;
			}

			$signingMethod = $memberData['signingMethod'] ?? null;
			if (!is_string($signingMethod) || $signingMethod === '')
			{
				continue;
			}

			// The value goes straight to the member row and signsafe reads an unknown one as "no method
			// chosen", which means ukep. Refusing is louder than storing a method nobody can honour.
			if (!SigningMethod::isValid($signingMethod))
			{
				$this->addError(new Error('Invalid `signingMethod` value'));

				continue;
			}

			if (
				$signingMethod === SigningMethod::GOS_KEY
				&& !$this->canAssigneeUseGoskey($assigneeEntityType, $document)
			)
			{
				$this->addError(new Error(
					Loc::getMessage('SIGN_CONTROLLER_MEMBER_DIRECTOR_SIGNING_NOT_ALLOWED'),
					'SIGNING_METHOD_PERMISSION_REQUIRED',
				));

				continue;
			}

			$assignee = $memberCollection->findFirstByRole(Role::ASSIGNEE);
			if ($assignee !== null)
			{
				$assignee->signingMethod = $signingMethod;
			}
		}

		return $memberCollection;
	}

	/**
	 * Goskey is the signing method of the company representative, so it requires a representative who
	 * is a person. A representative given as a structure role is refused: a role is a position and
	 * `$representativeId` holds the role id, so there is nobody to sign.
	 *
	 * The provider is checked here as well: the company is chosen on an earlier wizard step, which
	 * stores its provider on the document, and only goskey-lite has a head-signing branch. The wizard
	 * shows no control for the other providers, so the value can only arrive without it.
	 *
	 * Whether the branch is open for this portal is not asked here: the signing service owns that
	 * rollout by client id and already answers it per company through
	 * `goskey_representative_signing_available`, which is what hides the control in the wizard.
	 */
	private function canAssigneeUseGoskey(
		string $assigneeEntityType,
		\Bitrix\Sign\Item\Document $document,
	): bool
	{
		return $assigneeEntityType === EntityType::COMPANY
			&& $document->providerCode === ProviderCode::GOS_KEY_LITE
		;
	}

	/**
	 * The wizard asks this on the step where the representative and his signing method are chosen, so a
	 * refusal names the person who caused it while he is still on the screen. The parties are stored by
	 * another action, and it makes the same check: the answer here is advice for the wizard, not the
	 * permission itself.
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_DOCUMENT_EDIT, AccessibleItemType::DOCUMENT, 'documentUid'),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT, AccessibleItemType::DOCUMENT, 'documentUid')
	)]
	public function checkRepresentativeSigningMethodAction(
		string $documentUid,
		int $representativeId,
		string $signingMethod,
		string $representativeEntityType = EntityType::USER,
	): array
	{
		if ($representativeId <= 0)
		{
			$this->addError(new Error("Invalid `representativeId` value"));

			return [];
		}

		if (!SigningMethod::isValid($signingMethod))
		{
			$this->addError(new Error('Invalid `signingMethod` value'));

			return [];
		}

		if ($signingMethod !== SigningMethod::GOS_KEY)
		{
			return [];
		}

		$document = $this->documentService->getByUid($documentUid);
		if (!$document)
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_MEMBER_DOCUMENT_NOT_FOUND')));

			return [];
		}

		// The assignee of a b2e document is the company itself, unless the representative is given as a
		// structure role - the same mapping the parties go through on their way to being stored.
		$assigneeEntityType = $representativeEntityType === EntityType::USER
			? EntityType::COMPANY
			: $representativeEntityType
		;
		if (!$this->canAssigneeUseGoskey($assigneeEntityType, $document))
		{
			$this->addError(new Error(
				Loc::getMessage('SIGN_CONTROLLER_MEMBER_DIRECTOR_SIGNING_NOT_ALLOWED'),
				'SIGNING_METHOD_PERMISSION_REQUIRED',
			));
		}

		return [];
	}

	/**
	 * @param string $documentUid
	 * @return array
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(ActionDictionary::ACTION_DOCUMENT_EDIT, AccessibleItemType::DOCUMENT, 'documentUid'),
		new Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT, AccessibleItemType::DOCUMENT, 'documentUid')
	)]
	public function cleanAction(string $documentUid): array
	{
		$removeResult = $this->memberService->cleanByDocumentUid($documentUid);
		if (!$removeResult->isSuccess())
		{
			$this->addErrors($removeResult->getErrors());

			return [];
		}

		return [];
	}

	/**
	 * @param string $uid
	 * @param string $channelType
	 * @param string $channelValue
	 *
	 * @return array
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'uid',
			ItemIdentifierType::MEMBER_UID,
		),
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'uid',
			ItemIdentifierType::MEMBER_UID,
		)
	)]
	public function modifyCommunicationChannelAction(string $uid, string $channelType, string $channelValue): array
	{
		$modifyResult = $this->memberService->modifyCommunicationChannel(
			$uid,
			$channelType,
			$channelValue,
		);

		if (!$modifyResult->isSuccess())
		{
			$this->addErrors($modifyResult->getErrors());

			return [];
		}

		return [];
	}

	/**
	 * @param string $uid
	 *
	 * @return array
	 */
	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'uid',
			ItemIdentifierType::MEMBER_UID,
		),
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'uid',
			ItemIdentifierType::MEMBER_UID,
		)
	)]
	public function loadCommunicationsAction(
		string $uid,
		Service\Integration\Crm\AccessService $crmAccessService,
	): array
	{
		$member = $this->memberService->getByUid($uid);

		if (!$member)
		{
			return [];
		}

		if ($crmAccessService->isContactReadDeniedByMember($member))
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_MEMBER_ACCESS_DENIED_TO_CONTACT_COMMUNICATIONS')));

			return [];
		}

		return $this->memberService->getCommunications($member);
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'uid',
			ItemIdentifierType::MEMBER_UID,
		),
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'uid',
			ItemIdentifierType::MEMBER_UID,
		)
	)]
	public function loadAppliedCommunicationAction(
		string $uid,
		Service\Integration\Crm\AccessService $crmAccessService,
	): array
	{
		$member = $this->memberService->getByUid($uid);

		if (!$member || !$member->channelType)
		{
			return [];
		}

		if ($crmAccessService->isContactReadDeniedByMember($member))
		{
			$this->addError(new Error(Loc::getMessage('SIGN_CONTROLLER_MEMBER_ACCESS_DENIED_TO_CONTACT_COMMUNICATIONS')));

			return [];
		}

		return [
			'type' => $member->channelType,
			'value' => $member->channelValue,
		];
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'memberUid',
			ItemIdentifierType::MEMBER_UID,
		),
		new Attribute\ActionAccess(
			ActionDictionary::ACTION_B2E_DOCUMENT_EDIT,
			AccessibleItemType::DOCUMENT,
			'memberUid',
			ItemIdentifierType::MEMBER_UID,
		)
	)]
	public function saveStampAction(string $memberUid, string $fileId): array
	{
		$fileController = new \Bitrix\Sign\Upload\StampUploadController();
		$uploader = new \Bitrix\UI\FileUploader\Uploader($fileController);
		$pendingFiles = $uploader->getPendingFiles([$fileId]);
		$file = $pendingFiles->get($fileId);

		$stampFileId = $file?->getFileId();
		if ($stampFileId === null)
		{
			$this->addError(new Error("File didnt loaded"));

			return [];
		}
		$member = $this->memberService->getByUid($memberUid);
		if ($member === null)
		{
			$pendingFiles->remove();
			$this->addError(new Error('Member not found'));

			return [];
		}

		$result = $this->memberService->saveStampFile($stampFileId, $member);
		if (!$result->isSuccess())
		{
			// The upload is deliberately left pending for the cleanup agent instead of being removed
			// here: by the time the service reports a failure the file may already be referenced by
			// the requisite of the company, and deleting it would break that reference.
			$this->addErrors($result->getErrors());

			return [];
		}

		// The stamp is the uploaded file itself, no copy of it is made: left pending, it would be
		// dropped by the uploader cleanup agent together with the stamp of the company.
		$pendingFiles->makePersistent();
		$savedFileId = (int)$result->getData()['fileId'];

		return [
			'id' => $savedFileId,
			'srcUri' => \CFile::getPath($stampFileId),
		];
	}

	/**
	 * The action resolves the document indirectly, by member id, so the coarse prefilters prove nothing
	 * about it: the stage of a member row is handed out only after the resolved document is checked.
	 *
	 * An unresolvable member and a member the caller may not read answer the same way, so member ids
	 * cannot be enumerated through the difference.
	 */
	public function loadStageAction(int $memberId): array
	{
		if ($memberId <= 0)
		{
			$this->addAccessDeniedError();

			return [];
		}

		$container = Service\Container::instance();
		$memberRepository = $container->getMemberRepository();
		$member = $memberRepository->getById($memberId);
		if ($member === null)
		{
			$this->addAccessDeniedError();

			return [];
		}

		$document = $container->getDocumentRepository()->getById($member->documentId);
		if ($document === null)
		{
			$this->addAccessDeniedError();

			return [];
		}

		if (!$this->canLoadMemberStage($member, $document))
		{
			$this->addAccessDeniedError();

			return [];
		}

		return Stage::createInstance($member, $document)->getInfo();
	}

	/**
	 * Either branch alone is enough: a signer reads the stage of their own row without any grant on the
	 * document, and whoever may read the document reads the stage of every member of it. Gating on the
	 * document grant alone would close the ordinary B2E signer out of their own grid row.
	 */
	private function canLoadMemberStage(Item\Member $member, Item\Document $document): bool
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($this->container->getSignMemberUserService()->checkAccessToMember($member, $currentUserId))
		{
			return true;
		}

		$accessController = $this->getAccessController();

		return IterationHelper::any(
			self::LOAD_STAGE_ACCESS_ACTIONS,
			static fn(string $action): bool => $accessController->checkByItem($action, $document),
		);
	}

	public function getAction(string $uid): array
	{
		$currentUserId = CurrentUser::get()->getId();
		$userService = $this->container->getSignMemberUserService();
		$member = $this->memberService->getByUid($uid);
		if (!$member)
		{
			$this->addError(new Error('Member not found'));

			return [];
		}
		if (!$userService->checkAccessToMember($member, $currentUserId))
		{
			$this->addError(new Error('Member not found'));

			return [];
		}

		return [
			'id' => $member->id,
			'uid' => $member->uid,
			'status' => MemberStatus::toPresentedView($member->status),
		];
	}

	/**
	 * Toggles the reversible annulment mark on a completed signer member,
	 * addressing it by its uid.
	 *
	 * `changed` reports whether this call performed the transition: a repeated
	 * request for an already applied state succeeds and returns the state with
	 * changed = false, so the caller can tell it apart from a real change.
	 *
	 * @return array{uid: string|null, isAnnulled: bool, canAnnul: bool, status: string, changed: bool}|array{}
	 */
	#[Attribute\ActionAccess(ActionDictionary::ACTION_DOCUMENT_ANNUL)]
	public function annulAction(string $uid, bool $annul): array
	{
		if (!FeatureResolver::instance()->released('kedoDocumentAnnul'))
		{
			$this->addError(new Error('Document annulment is not available'));

			return [];
		}

		$container = Service\Container::instance();
		$member = $container->getMemberRepository()->getByUid($uid);
		if ($member === null || $member->documentId === null)
		{
			$this->addError(new Error('Member not found'));

			return [];
		}

		$document = $container->getDocumentRepository()->getById($member->documentId);
		if ($document === null)
		{
			$this->addError(new Error('Member not found'));

			return [];
		}

		// The annulment mark is a B2E/KEDO-only feature; reject non-B2E documents.
		if (!DocumentScenario::isB2EScenario($document->scenario))
		{
			$this->addError(new Error('Only b2e documents can be annulled'));

			return [];
		}

		$userId = (int)CurrentUser::get()->getId();

		// There is no accessible item type for a member record, so the annul owner
		// scope is guarded manually here against the document that owns the member
		// (IDOR), mirroring the manual guard used by getAction/checkAccessToMember.
		$canAnnul = $this->createAnnulPermission($userId)->canAnnulDocumentOwnedBy($document->createdById);
		if (!$canAnnul)
		{
			$this->addError(new Error('Access denied', 'ACCESS_DENIED'));

			return [];
		}

		$result = (new Operation\AnnulDocument($member, $annul, $userId))->launch();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$data = $result->getData();

		return $this->buildAnnulMemberData(
			$member->uid,
			(bool)$data['isAnnulled'],
			$canAnnul,
			(bool)$data['changed'],
		);
	}

	/**
	 * Applies the annulment mark to many member records at once, addressing them
	 * by uid.
	 *
	 * The annul permission scope is resolved once and reused for every row (owner
	 * scope of the SIGN_DOCUMENT_ANNUL right). Each requested uid falls into exactly
	 * one counter, a uid with no record behind it included; per-record statuses are
	 * not returned, the grid reloads afterwards.
	 *
	 * @param list<string> $uids
	 *
	 * @return array{changed: int, unchanged: int, forbidden: int, skipped: int}
	 */
	#[Attribute\ActionAccess(ActionDictionary::ACTION_DOCUMENT_ANNUL)]
	public function annulBatchAction(array $uids, bool $annul): array
	{
		$counters = ['changed' => 0, 'unchanged' => 0, 'forbidden' => 0, 'skipped' => 0];

		if (!FeatureResolver::instance()->released('kedoDocumentAnnul'))
		{
			$this->addError(new Error('Document annulment is not available'));

			return $counters;
		}

		$uids = array_values(array_unique(array_filter(
			array_map(static fn(mixed $uid): string => (string)$uid, $uids),
			static fn(string $uid): bool => $uid !== '',
		)));
		if ($uids === [])
		{
			return $counters;
		}

		if (count($uids) > self::MAX_ANNUL_BATCH_COUNT)
		{
			$this->addError(new Error(
				'Too many members for batch annulment',
				'SIGN_ANNUL_BATCH_LIMIT_EXCEEDED',
				['limit' => self::MAX_ANNUL_BATCH_COUNT],
			));

			return $counters;
		}

		$userId = (int)CurrentUser::get()->getId();
		$annulPermission = $this->createAnnulPermission($userId);

		$container = Service\Container::instance();
		$members = $container->getMemberRepository()->listByUids($uids);

		// A uid with no record of its own (deleted or stale) is never iterated below,
		// so it is counted here to keep the counters covering the whole input.
		$counters['skipped'] += count($uids) - $members->count();

		$documentIds = [];
		foreach ($members as $member)
		{
			if ($member->documentId !== null)
			{
				$documentIds[$member->documentId] = true;
			}
		}

		$documents = $documentIds === []
			? []
			: $container->getDocumentRepository()->listByIds(array_keys($documentIds))->getArrayByIds()
		;

		// Records actually changed by this call, grouped by their document: the side
		// effects are emitted per document, not per record.
		$changedByDocument = [];

		foreach ($members as $member)
		{
			$document = $member->documentId !== null ? ($documents[$member->documentId] ?? null) : null;
			if ($document === null)
			{
				$counters['skipped']++;

				continue;
			}

			if (!$annulPermission->canAnnulDocumentOwnedBy($document->createdById))
			{
				$counters['forbidden']++;

				continue;
			}

			// B2E/KEDO-only feature: non-B2E members are ineligible.
			if (!DocumentScenario::isB2EScenario($document->scenario))
			{
				$counters['skipped']++;

				continue;
			}

			// Predicate on the persisted role and status of the member record.
			if ($member->role !== Role::SIGNER || $member->status !== MemberStatus::DONE)
			{
				$counters['skipped']++;

				continue;
			}

			// Cheap pre-check: skip the operation when the snapshot is already at
			// the target. The operation re-reads the member fresh and is the real
			// source of truth on whether a state transition happened.
			if ($member->annulled === $annul)
			{
				$counters['unchanged']++;

				continue;
			}

			// The batch owns the side effects of the whole action, so the operation
			// emits none of its own per record.
			$result = (new Operation\AnnulDocument($member, $annul, $userId, withSideEffects: false))->launch();
			if (!$result->isSuccess())
			{
				$counters['skipped']++;

				continue;
			}

			// Gate changed/side effects on the operation's real transition, not on the
			// controller's pre-fetch snapshot: on a race the operation no-ops (a
			// concurrent request already reached the target) and reports changed
			// === false, which must count as unchanged and notify nobody.
			if ($result->getData()['changed'] ?? false)
			{
				$counters['changed']++;
				$changedByDocument[(int)$member->documentId][] = $member;
			}
			else
			{
				$counters['unchanged']++;
			}
		}

		foreach ($changedByDocument as $documentId => $changedMembers)
		{
			$this->emitAnnulmentSideEffects($documents[$documentId], $changedMembers, $annul, $userId);
		}

		return $counters;
	}

	/**
	 * Side effects of the batch action for one document: a single timeline event for
	 * the whole action, plus the HR-bot cards deferred to a background job of this
	 * request.
	 *
	 * One event per document, not per record: the records share a CRM item, so
	 * per-record events would reload it and push the same activity N times over. The
	 * event still names the employee when the action changed exactly one record of
	 * the document - there is nothing to aggregate then; above one record it reports
	 * how many signings changed, so the entry is not read as a whole document being
	 * annulled.
	 *
	 * Both side effects are best-effort: the persisted mark is the source of truth
	 * and a failure here never rolls it back.
	 *
	 * @param list<Item\Member> $changedMembers Records actually changed.
	 */
	private function emitAnnulmentSideEffects(
		Item\Document $document,
		array $changedMembers,
		bool $annul,
		int $userId,
	): void
	{
		$changedCount = count($changedMembers);

		try
		{
			if (Loader::includeModule('crm'))
			{
				$this->container->getEventHandlerService()->handleDocumentAnnulled(
					$document,
					$userId,
					$annul,
					$changedCount === 1 ? $changedMembers[0] : null,
					$changedCount,
				);
			}
		}
		catch (\Throwable $e)
		{
			$this->container->getLogger('Controller')->error(
				'Failed to emit annulment timeline event for document {documentId}: {errorsText}',
				[
					'documentId' => $document->id,
					'errorsText' => $e->getMessage(),
				],
			);
		}

		try
		{
			$this->container->getHrBotMessageService()->scheduleMembersAnnulled(
				$document,
				$changedMembers,
				$annul,
				$userId,
			);
		}
		catch (\Throwable $e)
		{
			// A failure to schedule the fan-out must not abort the remaining
			// documents of the batch: their marks are written and their own side
			// effects are still due.
			$this->container->getLogger('Controller')->error(
				'Failed to schedule annulment cards for document {documentId}: {errorsText}',
				[
					'documentId' => $document->id,
					'errorsText' => $e->getMessage(),
				],
			);
		}
	}

	protected function createAnnulPermission(int $userId): DocumentAnnulPermission
	{
		return DocumentAnnulPermission::forUser($userId);
	}

	/**
	 * @return array{uid: string|null, isAnnulled: bool, canAnnul: bool, status: string, changed: bool}
	 */
	private function buildAnnulMemberData(
		?string $uid,
		bool $isAnnulled,
		bool $canAnnul,
		bool $changed,
	): array
	{
		return [
			'uid' => $uid,
			'isAnnulled' => $isAnnulled,
			'canAnnul' => $canAnnul,
			'status' => $isAnnulled ? 'annulled' : 'signed',
			'changed' => $changed,
		];
	}

	private function getSyncMembersLock(string $docUid): bool
	{
		return Main\Application::getConnection()->lock($this->getSyncMembersLockName($docUid));
	}

	private function releaseSyncMembersLock(string $docUid): bool
	{
		return Main\Application::getConnection()->unlock($this->getSyncMembersLockName($docUid));
	}

	private function getSyncMembersLockName(string $docUid): string
	{
		return 'sign_sync_members_' . $docUid;
	}
}
