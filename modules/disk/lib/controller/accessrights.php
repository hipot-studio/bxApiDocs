<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Controller\ActionFilter\RequiredParameter;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\Flipchart\BoardSessionTerminationService;
use Bitrix\Disk\Document\OnlyOffice\Service\OnlyOfficeSessionTerminationService;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\UnifiedLinkAccessChecker;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Command\UnifiedLink\SetAccessCommand;
use Bitrix\Disk\Internal\Enum\ExternalLinkDisableReason;
use Bitrix\Disk\Internal\Enum\MembersAccessDisableReason;
use Bitrix\Disk\Internal\Entity\Audit\AuditChannel;
use Bitrix\Disk\Internal\Repository\UnifiedLinkAccessRepository;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContextStack;
use Bitrix\Disk\Internal\Service\Audit\AuditActionContextResolver;
use Bitrix\Disk\Internal\Service\Audit\AuditCaptureService;
use Bitrix\Disk\Internal\Service\Audit\AuditRightsChangeAuditService;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionResult;
use Bitrix\Disk\Internal\Service\Access\AccessContactCandidateProvider;
use Bitrix\Disk\Internal\Service\Access\AccessContactEligibilityChecker;
use Bitrix\Disk\Internal\Service\Access\AccessContactResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\Configuration as UnifiedLinkConfiguration;
use Bitrix\Disk\Internal\Enum\SharingMemberType;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\File;
use \Bitrix\Disk\Folder;
use Bitrix\Disk;
use Bitrix\Disk\Internals\Engine\ActionFilter\CheckReadPermission;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectOptionsTable;
use Bitrix\Disk\Internals\SharingTable;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\Type\AccessRight;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Disk\User;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessCheckHandler;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkParentFoldersAccessCheckHandler;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

final class AccessRights extends Engine\Controller
{
	protected RequiredParameter $requiredParameterFilter;
	protected UnifiedLinkAccessChecker $readAccessChecker;
	protected ExternalLinkProvider $externalLinkProvider;
	private ?AccessContactResolver $accessContactResolver = null;

	public function __construct(?Request $request = null)
	{
		$this->requiredParameterFilter = new RequiredParameter('object', false);

		$this->readAccessChecker = new UnifiedLinkAccessChecker(
			targetAccessLevel: UnifiedLinkAccessLevel::Read,
			argumentName: 'object',
		);

		$this->externalLinkProvider = ServiceLocator::getInstance()->get(ExternalLinkProvider::class);

		parent::__construct($request);
	}

	/**
	 * {@inheritDoc}
	 */
	public function configureActions(): array
	{
		return [
			'getUnified' => [
				'-prefilters' => [
					CheckReadPermission::class,
				],
				'+prefilters' => [
					$this->requiredParameterFilter,
					$this->readAccessChecker,
				],
				'+postfilters' => [
					$this->requiredParameterFilter,
				],
			],
			'getUnifiedAccessContact' => [
				'-prefilters' => [
					CheckReadPermission::class,
				],
				'+prefilters' => [
					$this->requiredParameterFilter,
					$this->readAccessChecker,
				],
				'+postfilters' => [
					$this->requiredParameterFilter,
				],
			],
			'setUnifiedPublicLinkRights' => [
				'-prefilters' => [
					CheckReadPermission::class,
				],
				'+prefilters' => [
					$this->requiredParameterFilter,
					$this->readAccessChecker,
				],
				'+postfilters' => [
					$this->requiredParameterFilter,
				],
			],
		];
	}

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				BaseObject::class,
				'object',
				function ($className, string $uniqueCode): ?BaseObject {
					return BaseObject::loadByUniqueCode($uniqueCode);
				},
			),
		];
	}

	public function getAction($objectId): ?array
	{
		/** @var File|Folder $object */
		$object = BaseObject::loadById((int)$objectId, array('STORAGE'));
		if (!$object || !$object->getRealObject())
		{
			$this->errorCollection[] = new \Bitrix\Disk\Internals\Error\Error('DISK_FOLDER_LIST_ERROR_COULD_NOT_FIND_OBJECT', \DiskFolderListAjaxController::ERROR_COULD_NOT_FIND_OBJECT);
			return null;
		}

		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext();
		if (!$securityContext || !$object->canRead($securityContext))
		{
			return null;
		}

		$canUpdate = $object->canUpdate($securityContext);

		return $this->getInternal($object, $canUpdate, $securityContext);
	}

	public function getUnifiedAction(?BaseObject $object): ?array
	{
		$accessLevel = $this->readAccessChecker->getAccessLevel();
		$canEdit = $accessLevel->value >= UnifiedLinkAccessLevel::Edit->value;
		$securityContext = $object?->getStorage()?->getCurrentUserSecurityContext(true);

		if (!$securityContext)
		{
			return null;
		}

		return $this->getInternal($object, $canEdit, $securityContext);
	}

	public function getAccessContactAction($objectId): ?array
	{
		$object = BaseObject::loadById((int)$objectId, ['STORAGE']);
		if (!$object || !$object->getRealObject())
		{
			$this->errorCollection[] = new Error(
				'DISK_FOLDER_LIST_ERROR_COULD_NOT_FIND_OBJECT',
				self::ERROR_COULD_NOT_FIND_OBJECT,
			);

			return null;
		}

		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext();
		if (!$securityContext || !$object->canRead($securityContext))
		{
			return null;
		}

		return $this->getAccessContactInternal($object, $securityContext);
	}

	public function getUnifiedAccessContactAction(?BaseObject $object): ?array
	{
		if ($object === null)
		{
			return null;
		}

		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext();
		if ($securityContext === null)
		{
			return null;
		}

		return $this->getAccessContactInternal($object, $securityContext);
	}

	public function setAction($objectId): void
	{
		/** @var File|Folder $object */
		$object = BaseObject::loadById((int)$objectId, array('STORAGE'));
		if(!$object)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_FOLDER_LIST_ERROR_COULD_NOT_FIND_OBJECT'), self::ERROR_COULD_NOT_FIND_OBJECT);
			$this->sendJsonErrorResponse();
		}

		$checkManageMembersAccessError = $this->checkManageMembersAccess($object);

		if ($checkManageMembersAccessError instanceof MembersAccessDisableReason)
		{
			$this->addError(new Error(
				message: '',
				customData: [
					'reason' => $checkManageMembersAccessError,
				],
			));

			return;
		}

		$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
		if(!$object->canChangeRights($securityContext))
		{
			$this->sendJsonAccessDeniedResponse();
		}

		$oldMembersAccess = $this->getMembersAccessView($object);

		$this->handlePrivateFlags($object);
		$this->processActionChangeSharingAndRights($object, $securityContext, $oldMembersAccess);
	}

	private function getPublicLinkData(
		BaseObject $baseObject,
		bool $isObjectEditable,
		Disk\Security\SecurityContext $securityContext,
	)
	{
		$realObject = $baseObject->getRealObject();
		$extLink = $this->externalLinkProvider->getForUse($realObject->getId());
		$ttl = null;
		$rightsList = null;

		if ($extLink)
		{
			$ttl = $extLink->getDeathTime()?->getTimestamp();

			$rightsList = [
				AccessRight::Read->value,
			];

			if ($isObjectEditable)
			{
				$rightsList[] = AccessRight::Edit->value;
			}
		}

		return [
			'enabled' => (bool)$extLink,
			'link' => $extLink ? $extLink->generateUrl() : null,
			'rights' => $extLink ? AccessRight::tryFromExternalLinkRights($extLink->getAccessRight())?->value : null,
			'canDownloadWithReadAccess' => $extLink ? $extLink->isCanDownloadWithReadAccess() : null,
			'canEditSettings' => $extLink ? $extLink->canEditSettings() : true,
			'publicLinkTtl' => $ttl,
			'password' => $extLink ? $extLink->hasPassword() : null,
			'disableReason' => $this->checkManageExternalLink($realObject, $securityContext),
			'rightsList' => $rightsList,
		];
	}

	public function setPublicLinkRightsAction(BaseObject $object): ?array
	{
		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext();
		if (!$securityContext || !$object->canRead($securityContext))
		{
			return null;
		}

		$canUpdate = $object->canUpdate($securityContext);

		return $this->setPublicLinkRights($object, $canUpdate, $securityContext);
	}

	public function setUnifiedPublicLinkRightsAction(?BaseObject $object): ?array
	{
		$accessLevel = $this->readAccessChecker->getAccessLevel();
		$canEdit = $accessLevel->value >= UnifiedLinkAccessLevel::Edit->value;
		$securityContext = $object?->getStorage()?->getCurrentUserSecurityContext(true);

		if (!$securityContext)
		{
			return null;
		}

		return $this->setPublicLinkRights($object, $canEdit, $securityContext);
	}

	protected function getInternal(
		BaseObject $object,
		bool $canEdit,
		Disk\Security\SecurityContext $securityContext,
	): ?array
	{
		$isFolder = $object instanceof Folder;
		$objectOptions = $object->getRealObject()->getObjectOptions();
		$isObjectEditable = $canEdit && $this->isObjectEditable($object->getRealObject());
		$membersAccessDisableReason = $this->checkManageMembersAccess($object->getRealObject());
		$membersAccess = $this->processActionShowSharingDetailChangeRights($object, $membersAccessDisableReason);
		if (self::shouldResolveAccessContact($membersAccess, $membersAccessDisableReason))
		{
			$membersAccess['accessContact'] = $this->getAccessContactResolver()->resolveFast(
				$object,
				(int)$this->getUser()->getId(),
			);
		}

		return [
			'fileName' => $object->getName(),
			'typeFile' => $isFolder ? null : $object->getTypeFile(),
			'isFolder' => $isFolder,
			'allowManagePublicAccessWithViewingRights' =>
				$objectOptions[ObjectOptionsTable::NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ]
			,
			'allowDownloadingWithViewingRights'	=> $objectOptions[ObjectOptionsTable::NAME_ALLOW_DOWNLOAD_ON_READ],
			'membersAccessDisableReason' => $membersAccessDisableReason,
			'membersAccess' => $membersAccess,
			'publicLink' => $this->getPublicLinkData(
				$object,
				$isObjectEditable,
				$securityContext,
			),
		];
	}

	private function getAccessContactInternal(
		BaseObject $object,
		Disk\Security\SecurityContext $securityContext,
	): array
	{
		$realObject = $object->getRealObject();
		$disableReason = $this->checkManageMembersAccess($realObject);
		if (
			$disableReason !== null
			|| !$realObject->supportsUnifiedLink()
			|| $object->canChangeRights($securityContext)
		)
		{
			return ['contact' => null];
		}

		return [
			'contact' => $this->getAccessContactResolver()->resolveExtended(
				$realObject,
				(int)$this->getUser()->getId(),
			),
		];
	}

	private static function shouldResolveAccessContact(
		array|false $membersAccess,
		?MembersAccessDisableReason $disableReason,
	): bool
	{
		return
			$disableReason === null
			&& is_array($membersAccess)
			&& ($membersAccess['canChangeRights'] ?? false) === false
			&& !empty($membersAccess['unifiedLink']['link'])
		;
	}

	private function getAccessContactResolver(): AccessContactResolver
	{
		$this->accessContactResolver ??= new AccessContactResolver(
			new AccessContactCandidateProvider(),
			new AccessContactEligibilityChecker(),
		);

		return $this->accessContactResolver;
	}

	protected function setPublicLinkRights(
		BaseObject $baseObject,
		bool $canEdit,
		Disk\Security\SecurityContext $securityContext,
	): ?array
	{
		$realObject = $baseObject->getRealObject();
		$checkManageExternalLinkError = $this->checkManageExternalLink($realObject, $securityContext);

		if ($checkManageExternalLinkError instanceof ExternalLinkDisableReason)
		{
			$this->addError(new Error(
				message: '',
				customData: [
					'reason' => $checkManageExternalLinkError,
				],
			));

			return null;
		}

		$data = $this->getRequest()->getJsonList()->toArray();
		$extLink = $this->externalLinkProvider->getForUse($realObject->getId());
		$isObjectEditable = $canEdit && $this->isObjectEditable($realObject);

		return $this->runPublicLinkRightsCommand(
			$realObject,
			$extLink,
			$data,
			$isObjectEditable,
			$securityContext,
		);
	}

	private function runPublicLinkRightsCommand(
		BaseObject $realObject,
		?Disk\ExternalLink $extLink,
		array $data,
		bool $isObjectEditable,
		Disk\Security\SecurityContext $securityContext,
	): ?array
	{
		$operationContext = AuditOperationContext::create(channel: AuditChannel::WEB);

		return AuditOperationContextStack::runWithContextIfEmpty(
			$operationContext,
			function () use ($realObject, $extLink, $data, $isObjectEditable, $securityContext): ?array {
				$transactionResult = (new AuditTransaction(Application::getConnection()))->execute(
					function (AuditTransaction $transaction) use (
						$realObject,
						$extLink,
						$data,
						$isObjectEditable,
						$securityContext,
					): AuditTransactionOutcome {
						$response = $this->applyPublicLinkRights(
							$realObject,
							$extLink,
							$data,
							$isObjectEditable,
							$securityContext,
							$transaction,
						);

						return $response === null
							? AuditTransactionOutcome::failure()
							: AuditTransactionOutcome::success($response)
						;
					},
				);

				if ($transactionResult->isSuccess())
				{
					return $transactionResult->getOutcome()->getValue();
				}

				$this->applyPublicLinkTransactionFailure($transactionResult);

				return null;
			},
		);
	}

	private function applyPublicLinkRights(
		BaseObject $realObject,
		?Disk\ExternalLink $extLink,
		array $data,
		bool $isObjectEditable,
		Disk\Security\SecurityContext $securityContext,
		AuditTransaction $auditTransaction,
	): ?array
	{
		$enabled = (bool)($data['enabled'] ?? false);
		$settingsFields = ['rights', 'canDownloadWithReadAccess', 'newPassword', 'publicLinkTtl'];

		if (!$enabled)
		{
			if ($extLink)
			{
				$externalLinkId = (int)$extLink->getId();
				if (!$extLink->delete($auditTransaction))
				{
					$this->addErrors($extLink->getErrors());

					return null;
				}

				$auditTransaction->addAfterCommit(
					static function () use ($realObject, $externalLinkId): void {
						Application::getInstance()->addBackgroundJob(
							static function () use ($realObject, $externalLinkId): void {
								$boardService = new BoardSessionTerminationService($realObject);
								$boardService->terminateSessionsByExternalLink($externalLinkId);

								$service = new OnlyOfficeSessionTerminationService($realObject);
								$service->terminateSessionsByExternalLink($externalLinkId);
							},
						);
					},
				);
			}

			return $this->getPublicLinkData(
				$realObject,
				$isObjectEditable,
				$securityContext,
			);
		}

		if ($extLink && array_key_exists('canEditSettings', $data))
		{
			$this->addError(new Error(
				'External link settings edit permission cannot be changed',
				Disk\ExternalLink::ERROR_SETTINGS_EDIT_DENIED,
			));

			return null;
		}

		if ($extLink && !$extLink->canEditSettings() && $this->hasAnyKey($data, $settingsFields))
		{
			$this->addError(new Error(
				'External link settings editing is denied',
				Disk\ExternalLink::ERROR_SETTINGS_EDIT_DENIED,
			));

			return null;
		}

		if (!$extLink)
		{
			$addData = [
				'CREATED_BY' => $this->getUser()->getId(),
				'TYPE' => ExternalLinkTable::TYPE_MANUAL,
			];

			if (array_key_exists('canEditSettings', $data))
			{
				$addData['CAN_EDIT_SETTINGS'] = (bool)$data['canEditSettings'];
			}

			if ($isObjectEditable && isset($data['rights']))
			{
				$rights = AccessRight::tryFrom($data['rights']);
				if ($rights)
				{
					$addData['ACCESS_RIGHT'] = $rights->toExternalLinkRight();
				}
			}

			if (isset($data['canDownloadWithReadAccess']))
			{
				$addData['CAN_DOWNLOAD_WITH_READ_ACCESS'] = $data['canDownloadWithReadAccess'] ? 1 : 0;
			}

			if (isset($data['newPassword']) && $data['newPassword'] !== false)
			{
				$addData['PASSWORD'] = (string)$data['newPassword'];
			}

			$publicLinkTtl = $data['publicLinkTtl'] ?? null;
			if (is_int($publicLinkTtl) && $publicLinkTtl > time())
			{
				$addData['DEATH_TIME'] = DateTime::createFromTimestamp($publicLinkTtl);
			}

			$extLink = $realObject->addExternalLink($addData, $auditTransaction);
			if (!$extLink)
			{
				$this->addErrors($realObject->getErrors());

				return null;
			}

			return $this->getPublicLinkData($realObject, $isObjectEditable, $securityContext);
		}

		$newAccessRight = $extLink->getAccessRight();
		if ($isObjectEditable && isset($data['rights']))
		{
			$rights = AccessRight::tryFrom($data['rights']);
			if ($rights)
			{
				$externalLinkRight = $rights->toExternalLinkRight();
				if ($externalLinkRight !== null)
				{
					$newAccessRight = $externalLinkRight;
				}
			}
		}

		$newCanDownloadWithReadAccess = isset($data['canDownloadWithReadAccess'])
			? (bool)$data['canDownloadWithReadAccess']
			: $extLink->isCanDownloadWithReadAccess()
		;
		if (
			$newAccessRight !== $extLink->getAccessRight()
			|| $newCanDownloadWithReadAccess !== $extLink->isCanDownloadWithReadAccess()
		)
		{
			if (!$extLink->changeAccessSettings($newAccessRight, $newCanDownloadWithReadAccess))
			{
				$this->addErrors($extLink->getErrors());

				return null;
			}
		}

		if (isset($data['newPassword']))
		{
			if ($data['newPassword'] === false)
			{
				if (!$extLink->revokePassword())
				{
					$this->addErrors($extLink->getErrors());

					return null;
				}
			}
			else
			{
				if (!$extLink->changePassword((string)$data['newPassword']))
				{
					$this->addErrors($extLink->getErrors());

					return null;
				}
			}
		}

		$publicLinkTtl = $data['publicLinkTtl'] ?? null;

		if (is_int($publicLinkTtl) || $publicLinkTtl === false)
		{
			$deathTime = $publicLinkTtl === false ? false : DateTime::createFromTimestamp($publicLinkTtl);

			if (!$deathTime)
			{
				if (!$extLink->revokeDeathTime())
				{
					$this->addErrors($extLink->getErrors());

					return null;
				}
			}
			elseif($publicLinkTtl > time())
			{
				if (!$extLink->changeDeathTime($deathTime))
				{
					$this->addErrors($extLink->getErrors());

					return null;
				}
			}
		}

		return $this->getPublicLinkData($realObject, $isObjectEditable, $securityContext);
	}

	private function applyPublicLinkTransactionFailure(AuditTransactionResult $result): void
	{
		$exception = $result->getExceptions()[0] ?? null;
		if ($exception !== null)
		{
			$this->addError(new Error($exception->getMessage()));

			return;
		}

		if (!$result->isRollbackConfirmed())
		{
			$this->addError(new Error('Public link transaction failed.'));
		}
	}

	private function hasAnyKey(array $data, array $keys): bool
	{
		foreach ($keys as $key)
		{
			if (array_key_exists($key, $data))
			{
				return true;
			}
		}

		return false;
	}

	private function getUser()
	{
		global $USER;

		return $USER;
	}

	/** @see DiskFolderListAjaxController::processActionShowSharingDetailChangeRights() */
	private function processActionShowSharingDetailChangeRights(
		BaseObject $object,
		?MembersAccessDisableReason $disableReason,
	)
	{
		$rightsManager = Driver::getInstance()->getRightsManager();
		$securityContext = $object->getStorage()?->getCurrentUserSecurityContext();
		if (!$securityContext || !$object->canRead($securityContext))
		{
			return $this->getUnifiedLinkOnlyMembersAccess($object);
		}
		//user has only read right. And he can't see on another sharing
		if (!$object->canShare($securityContext) && !$object->canChangeRights($securityContext))
		{
			/** @var User $user */
			$user = User::getById($this->getUser()->getId());
			$entityList = array(
				array(
					'entityId' => \Bitrix\Disk\Sharing::CODE_USER . $this->getUser()->getId(),
					'name' => $user->getFormattedName(),
					'right' => $rightsManager->getPseudoMaxTaskByObjectForUser($object, $user->getId()),
					'avatar' => $user->getAvatarSrc(),
					'ownAvatar' => $user->getOwnAvatarSrc(),
					'type' => 'users',
					'memberType' => SharingMemberType::forUser($user)?->value,
				)
			);
		}
		else
		{
			$entityList = self::sortMembersByGrantOrder($object->getMembersOfSharing());
		}

		$selected = array();
		foreach ($entityList as $entity)
		{
			$selected[] = $entity['entityId'];
		}
		$destination = \Bitrix\Disk\Ui\Destination::getSocNetDestination($this->getUser()->getId(), $selected);

		$rightsManager = Driver::getInstance()->getRightsManager();
		$canOnlyShare = $object->canShare($securityContext) && !$object->canChangeRights($securityContext);
		$maxTaskName = $rightsManager::TASK_FULL;
		if ($canOnlyShare)
		{
			$maxTaskName = $rightsManager->getPseudoMaxTaskByObjectForUser($object, $this->getUser()->getId());
		}
		$owner = $this->getOwner($object);
		$owner['canOnlyShare'] = $canOnlyShare;
		$owner['maxTaskName'] = $maxTaskName;

		$response = array(
			'members' => $entityList,
			'owner' => $owner,
			'destination' => array(
				'items' => array(
					'users' => $destination['USERS'],
					'groups' => array(),
					'sonetgroups' => $destination['SONETGROUPS'],
					'department' => $destination['DEPARTMENT'],
					'departmentRelation' => $destination['DEPARTMENT_RELATION'],
				),
				'itemsLast' => array(
					'users' => $destination['LAST']['USERS'],
					'groups' => array(),
					'sonetgroups' => $destination['LAST']['SONETGROUPS'],
					'department' => $destination['LAST']['DEPARTMENT'],
				),
				'itemsSelected' => $destination['SELECTED'],
			),
			'canChangeRights' =>
				is_null($disableReason)
				&& $object->canChangeRights($securityContext)
			,
		);

		$realObject = $object->getRealObject();

		if ($realObject->supportsUnifiedLink())
		{
			$response['unifiedLink'] = $this->getUnifiedLinkData($realObject);
		}

		return $response;
	}

	/**
	 * Orders members the way the access was granted, which is the order the user picked them in.
	 * {@see BaseObject::getMembersOfSharing()} walks entity types one after another, so a user added
	 * between two departments lands after both of them once the popup reloads the rights.
	 * Only this popup is reordered: the same list is served to the file grid, the folder list and the
	 * file view panel, and those keep the grouping by type.
	 * A member with no sharing id keeps its place relative to the others: sorting is stable since PHP 8.
	 * @param array $members Members of sharing.
	 * @return array
	 */
	private static function sortMembersByGrantOrder(array $members): array
	{
		usort(
			$members,
			static fn(array $a, array $b): int => (int)($a['sharingId'] ?? 0) <=> (int)($b['sharingId'] ?? 0),
		);

		return $members;
	}

	protected function getOwner(BaseObject $object): array
	{
		$proxyType = $object->getRealObject()->getStorage()->getProxyType();
		if ($proxyType instanceof \Bitrix\Im\Disk\ProxyType\Im)
		{
			$createUser = $object->getRealObject()->getCreateUser();

			return [
				'name' => $createUser->getFormattedName(),
				'avatar' => $createUser->getAvatarSrc(58, 58),
				'link' => $createUser->getDetailUrl(),
			];
		}

		return [
			'name' => $proxyType->getEntityTitle(),
			'avatar' => $proxyType->getEntityImageSrc(58, 58),
			'link' => $proxyType->getEntityUrl(),
		];
	}

	/**
	 * Visitor without direct rights sees no members, but still has to get the link he came by.
	 */
	private function getUnifiedLinkOnlyMembersAccess(BaseObject $object): array|false
	{
		$realObject = $object->getRealObject();
		if (!$realObject->supportsUnifiedLink())
		{
			return false;
		}

		$parentFoldersAccessLevel = $this->getUnifiedLinkParentFoldersAccessLevel($realObject);
		$accessLevel = $this->getUnifiedLinkCurrentAccessLevel($realObject, $parentFoldersAccessLevel);
		if ($accessLevel === UnifiedLinkAccessLevel::Denied)
		{
			return false;
		}

		return [
			'members' => [],
			'canChangeRights' => false,
			'unifiedLink' => $this->getUnifiedLinkData($realObject, $accessLevel),
		];
	}

	private function getUnifiedLinkData(
		BaseObject $baseObject,
		?UnifiedLinkAccessLevel $accessLevel = null,
	): array
	{
		$link = Driver::getInstance()->getUrlManager()->getUnifiedLink($baseObject, [
			'absolute' => true,
		]);

		$parentFoldersAccessLevel = $this->getUnifiedLinkParentFoldersAccessLevel($baseObject);

		return [
			'link' => $link,
			'currentAccessLevel' => $accessLevel
				?? $this->getUnifiedLinkCurrentAccessLevel($baseObject, $parentFoldersAccessLevel)
			,
			'availableAccessLevels' => array_map(static fn (UnifiedLinkAccessLevel $accessLevel) => [
				'value' => $accessLevel->value,
				'name' => $accessLevel->getTitle(),
			], $this->getUnifiedLinkAvailableAccessLevels($parentFoldersAccessLevel)),
		];
	}

	protected function getUnifiedLinkParentFoldersAccessLevel(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		$userId = (int)CurrentUser::get()->getId();
		// An anonymous request resolves to the system user, who is an intranet one by definition
		// and would be granted the level of the link.
		if ($userId <= 0)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		return (new UnifiedLinkParentFoldersAccessCheckHandler($userId))->check($baseObject);
	}

	protected function getUnifiedLinkAvailableAccessLevels(UnifiedLinkAccessLevel $parentFoldersAccessLevel): array
	{
		return array_values(array_filter(
			UnifiedLinkAccessLevel::cases(),
			static fn (UnifiedLinkAccessLevel $accessLevel): bool => $accessLevel->value >= $parentFoldersAccessLevel->value,
		));
	}

	protected function getUnifiedLinkCurrentAccessLevel(
		BaseObject $baseObject,
		UnifiedLinkAccessLevel $parentFoldersAccessLevel,
	): UnifiedLinkAccessLevel
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		$currentAccessLevel = (new UnifiedLinkAccessCheckHandler($userId))->check($baseObject);

		return $parentFoldersAccessLevel->value > $currentAccessLevel->value
			? $parentFoldersAccessLevel
			: $currentAccessLevel
		;
	}

	/**
	 * @throws ArgumentException
	 */
	protected function handlePrivateFlags(BaseObject $baseObject): void
	{
		$realObject = $baseObject->getRealObject();

		$allowManagePublicAccessWithViewingRights = $this->toBoolOrNull(
			$this->request->getPost('allowManagePublicAccessWithViewingRights')
		);

		if (is_bool($allowManagePublicAccessWithViewingRights))
		{
			$realObject->setObjectOption(
				name: ObjectOptionsTable::NAME_ALLOW_MANAGE_PUBLIC_ACCESS_ON_READ,
				value: $allowManagePublicAccessWithViewingRights,
			);
		}

		$allowDownloadingWithViewingRights = $this->toBoolOrNull(
			$this->request->getPost('allowDownloadingWithViewingRights')
		);

		if (is_bool($allowDownloadingWithViewingRights))
		{
			$realObject->setObjectOption(
				name: ObjectOptionsTable::NAME_ALLOW_DOWNLOAD_ON_READ,
				value: $allowDownloadingWithViewingRights,
			);
		}
	}

	protected function processActionChangeSharingAndRights(
		BaseObject $object,
		Disk\Security\SecurityContext $securityContext,
		array $oldMembersAccess = [],
	)
	{
		$entityToNewShared = $this->request->getPost('entityToNewShared');
		$transactionResult = $this->runSharingAndRightsCommand(
			$object,
			$securityContext,
			$oldMembersAccess,
			$entityToNewShared,
		);
		if (!$transactionResult->isSuccess())
		{
			$this->applySharingAndRightsTransactionFailure($transactionResult);
			$errorResponse = $transactionResult->getOutcome()->getValue();
			if (is_array($errorResponse))
			{
				$this->sendJsonErrorResponse($errorResponse);
			}
			else
			{
				$this->sendJsonErrorResponse();
			}

			return;
		}

		$response = $transactionResult->getOutcome()->getValue();
		if (is_array($response) && $response !== [])
		{
			$this->sendJsonSuccessResponse($response);
		}
		else
		{
			$this->sendJsonSuccessResponse();
		}
	}

	private function runSharingAndRightsCommand(
		File|Folder $object,
		Disk\Security\SecurityContext $securityContext,
		array $oldMembersAccess,
		mixed $entityToNewShared,
	): AuditTransactionResult
	{
		$operationContext = AuditOperationContext::create(channel: AuditChannel::WEB);

		return AuditOperationContextStack::runWithContextIfEmpty(
			$operationContext,
			function () use (
				$object, $securityContext, $oldMembersAccess, $entityToNewShared,
			): AuditTransactionResult {
				$command = fn(AuditTransaction $transaction): AuditTransactionOutcome => $this->applySharingAndRightsCommand(
					$object,
					$securityContext,
					$oldMembersAccess,
					$entityToNewShared,
					$transaction,
				);
				if (AuditTransaction::isActive())
				{
					$outcome = AuditTransaction::run(static fn(AuditTransaction $owner) => $owner->join($command));

					return $outcome->isSuccess() ? AuditTransactionResult::committed($outcome)
						: AuditTransactionResult::rolledBack($outcome);
				}

				return (new AuditTransaction(Application::getConnection()))->execute($command);
			},
		);
	}

	private function applySharingAndRightsCommand(
		File|Folder $object,
		Disk\Security\SecurityContext $securityContext,
		array $oldMembersAccess,
		mixed $entityToNewShared,
		AuditTransaction $transaction,
	): AuditTransactionOutcome
	{
		$auditService = new AuditRightsChangeAuditService();
		$before = $auditService->capture($object);
		Driver::getInstance()->getTrackedObjectManager()->refresh($object, $this->getUser()->getId());

		$isBoard = $object instanceof File && $object->getTypeFile() == TypeFile::FLIPCHART;
		$shouldSendBoardAccessAnalytics = false;
		$currentUserId = (int)$this->getUser()->getId();

		if (!empty($entityToNewShared) && is_array($entityToNewShared))
		{
			$unifiedLinkAccessChanged = false;
			$unifiedLinkData = $entityToNewShared['unifiedLink'] ?? null;
			if (is_array($unifiedLinkData) && isset($unifiedLinkData['newAccessLevel']))
			{
				$unifiedLinkNewAccessLevel = UnifiedLinkAccessLevel::tryFrom(
					(string)$unifiedLinkData['newAccessLevel'],
				);
				$realObject = $object->getRealObject();
				if ($unifiedLinkNewAccessLevel !== null)
				{
					if (!$this->isUnifiedLinkAccessLevelAllowed($realObject, $unifiedLinkNewAccessLevel))
					{
						$this->addError(new Error(
							message: 'Access level is lower than allowed.',
							code: 'DISK_UNIFIED_LINK_ACCESS_LEVEL_RESTRICTED',
						));

						return AuditTransactionOutcome::failure([
							'unifiedLinkChangeError' => true,
						]);
					}

					$oldUnifiedLinkAccessLevel = UnifiedLinkAccessRepository::getByObjectId($realObject->getId())
						?? UnifiedLinkConfiguration::getDefaultAccessLevelForObject($realObject)
					;
					$result = (new SetAccessCommand(
						$realObject->getId(),
						$unifiedLinkNewAccessLevel,
						$currentUserId,
					))->run();
					if (!$result->isSuccess())
					{
						$this->addErrors($result->getErrors());

						return AuditTransactionOutcome::failure([
							'unifiedLinkChangeError' => true,
						]);
					}

					$unifiedLinkAccessChanged = $oldUnifiedLinkAccessLevel !== $unifiedLinkNewAccessLevel;
				}
			}

			$newExtendedRights = [];
			foreach ($entityToNewShared as $entityId => $right)
			{
				if (!is_array($right))
				{
					continue;
				}

				switch ($right['right'] ?? null)
				{
					case Disk\RightsManager::TASK_READ:
					case Disk\RightsManager::TASK_ADD:
					case Disk\RightsManager::TASK_EDIT:
					case Disk\RightsManager::TASK_FULL:
						$newExtendedRights[$entityId] = $right['right'];
						break;
				}
			}

			$query = Disk\Sharing::getList([
				'filter' => [
					'REAL_OBJECT_ID' => $object->getRealObjectId(),
					'REAL_STORAGE_ID' => $object->getRealObject()->getStorageId(),
					'!=STATUS' => SharingTable::STATUS_IS_DECLINED,
					'PARENT_ID' => null,
				],
			]);
			$needToOverwrite = $needToDelete = [];
			while ($sharingRow = $query->fetch())
			{
				if (isset($newExtendedRights[$sharingRow['TO_ENTITY']]))
				{
					if ($newExtendedRights[$sharingRow['TO_ENTITY']] !== $sharingRow['TASK_NAME'])
					{
						$needToOverwrite[$sharingRow['TO_ENTITY']] = $sharingRow;
					}
					else
					{
						unset($newExtendedRights[$sharingRow['TO_ENTITY']]);
					}
				}
				else
				{
					$needToDelete[$sharingRow['TO_ENTITY']] = $sharingRow;
				}
			}

			$needToAdd = array_diff_key($newExtendedRights, $needToOverwrite);
			foreach ($needToAdd as $entity => $taskName)
			{
				if (!Disk\Sharing::hasRightToKnowAboutEntity($currentUserId, $entity))
				{
					unset($needToAdd[$entity]);
				}
			}

			if ($needToAdd)
			{
				$sharings = Disk\Sharing::addToManyEntities(
					[
						'FROM_ENTITY' => Disk\Sharing::CODE_USER . $currentUserId,
						'REAL_OBJECT' => $object,
						'CREATED_BY' => $currentUserId,
						'CAN_FORWARD' => false,
					],
					$needToAdd,
					$this->errorCollection,
					$transaction,
				);
				if ($sharings === null)
				{
					return AuditTransactionOutcome::failure();
				}

				$destinationCodes = [];
				foreach ($sharings as $sharing)
				{
					$destinationCodes[] = $sharing->getToEntity();
				}
				if ($destinationCodes !== [])
				{
					$finderCodes = \Bitrix\Main\FinderDestTable::convertRights(
						array_unique($destinationCodes),
					);
					$transaction->addAfterCommit(static function () use ($finderCodes): void {
						\Bitrix\Main\FinderDestTable::merge([
							'CONTEXT' => 'DISK_SHARE',
							'CODE' => $finderCodes,
						]);
					});
				}

				$shouldSendBoardAccessAnalytics = $isBoard;
			}

			if ($needToOverwrite)
			{
				$rightsManager = Driver::getInstance()->getRightsManager();
				foreach ($needToOverwrite as $sharingRow)
				{
					if (
						!$rightsManager->deleteByDomain(
							$object->getRealObject(),
							$rightsManager->getSharingDomain($sharingRow['ID']),
							$transaction,
						)
					)
					{
						$this->addErrors($rightsManager->getErrors());

						return AuditTransactionOutcome::failure();
					}
				}

				$newRights = [];
				foreach ($needToOverwrite as $sharingRow)
				{
					$newTaskName = $newExtendedRights[$sharingRow['TO_ENTITY']];
					$sharingDomain = $rightsManager->getSharingDomain($sharingRow['ID']);
					$isSharingToGroup = $sharingRow['TYPE'] == SharingTable::TYPE_TO_GROUP;
					$accessCodeSuffix = $isSharingToGroup ? '_K' : '';
					$newRights[] = [
						'ACCESS_CODE' => $sharingRow['TO_ENTITY'] . $accessCodeSuffix,
						'TASK_ID' => $rightsManager->getTaskIdByName($newTaskName),
						'DOMAIN' => $sharingDomain,
					];
					SharingTable::updateBatch(['TASK_NAME' => $newTaskName], ['ID' => $sharingRow['ID']]);
					if ($sharingRow['TYPE'] == SharingTable::TYPE_TO_DEPARTMENT)
					{
						SharingTable::updateBatch(['TASK_NAME' => $newTaskName], ['PARENT_ID' => $sharingRow['ID']]);
					}
				}

				if ($newRights && !$rightsManager->append($object->getRealObject(), $newRights, $transaction))
				{
					$this->addErrors($rightsManager->getErrors());

					return AuditTransactionOutcome::failure();
				}
			}

			if ($needToDelete)
			{
				$ids = array_column($needToDelete, 'ID');
				foreach (Disk\Sharing::getModelList(['filter' => ['ID' => $ids]]) as $sharing)
				{
					if (!$sharing->delete($currentUserId, true, $transaction))
					{
						$this->addErrors($sharing->getErrors());

						return AuditTransactionOutcome::failure();
					}
				}
			}

			$backgroundPayload = $this->prepareRightsChangeBackgroundJobs(
				$object,
				$securityContext,
				$shouldSendBoardAccessAnalytics,
			);
			$this->scheduleRightsAuditEvents($object, $oldMembersAccess, $before, $transaction, $auditService);

			$transaction->addAfterCommit(function () use ($backgroundPayload): void {
				$this->scheduleRightsChangeBackgroundJobs($backgroundPayload);
			});

			return AuditTransactionOutcome::success([
				'unifiedLinkAccessChanged' => $unifiedLinkAccessChanged,
			]);
		}

		foreach ($object->getRealObject()->getSharingsAsReal() as $sharing)
		{
			if (!$sharing->delete($currentUserId, true, $transaction))
			{
				$this->addErrors($sharing->getErrors());

				return AuditTransactionOutcome::failure();
			}
		}

		$backgroundPayload = $this->prepareRightsChangeBackgroundJobs($object, $securityContext);
		$this->scheduleRightsAuditEvents($object, $oldMembersAccess, $before, $transaction, $auditService);

		$transaction->addAfterCommit(function () use ($backgroundPayload): void {
			$this->scheduleRightsChangeBackgroundJobs($backgroundPayload);
		});

		return AuditTransactionOutcome::success([]);
	}

	private function applySharingAndRightsTransactionFailure(AuditTransactionResult $result): void
	{
		$exception = $result->getExceptions()[0] ?? null;
		if ($exception !== null)
		{
			$this->addError(new Error($exception->getMessage()));

			return;
		}

		if (!$result->isRollbackConfirmed())
		{
			$this->addError(new Error('Sharing and rights transaction failed.'));
		}
	}

	private function getMembersAccessView(File|Folder $object): array
	{
		$realObject = $object->getRealObject();
		if (!$realObject)
		{
			return [];
		}

		$query = SharingTable::getList([
			'select' => [
				'TO_ENTITY',
				'TASK_NAME',
			],
			'filter' => [
				'REAL_OBJECT_ID' => $realObject->getId(),
				'REAL_STORAGE_ID' => $realObject->getStorageId(),
				'!=STATUS' => SharingTable::STATUS_IS_DECLINED,
				'PARENT_ID' => null,
			],
		]);

		$membersAccess = [];
		while ($sharing = $query->fetch())
		{
			$membersAccess[] = [
				'entityId' => (string)$sharing['TO_ENTITY'],
				'right' => (string)$sharing['TASK_NAME'],
			];
		}

		return $membersAccess;
	}

	private function scheduleRightsAuditEvents(
		File|Folder $object,
		array $oldMembersAccess,
		?array $before,
		AuditTransaction $transaction,
		AuditRightsChangeAuditService $auditService,
	): void
	{
		AuditCaptureService::runFailOpen(function () use (
			$object, $oldMembersAccess, $before, $transaction, $auditService,
		): \Bitrix\Main\Result {
			$context = AuditOperationContext::create(
				operationId: AuditOperationContextStack::current()?->operationId,
				actionContext: AuditOperationContextStack::current()?->actionContext
					?? (new AuditActionContextResolver())->resolveForWeb((int)$this->getUser()->getId()),
			);

			return $auditService->schedule($object, $before, $context, $transaction, [
				'before' => $oldMembersAccess,
				'after' => $this->getMembersAccessView($object),
			]);
		});
	}

	protected function isUnifiedLinkAccessLevelAllowed(
		BaseObject $baseObject,
		UnifiedLinkAccessLevel $accessLevel,
	): bool
	{
		return $accessLevel->value >= $this->getUnifiedLinkParentFoldersAccessLevel($baseObject)->value;
	}

	/**
	 * @param BaseObject $object
	 * @return void
	 */
	private function terminateSessionsWithInsufficientRights(BaseObject $object): void
	{
		$sessionTerminationServices = (new Disk\Document\SessionTerminationServiceFactory($object))->createAll();
		foreach ($sessionTerminationServices as $sessionTerminationService)
		{
			$sessionTerminationService->terminateSessionsWithInsufficientRights();
		}
	}

	/**
	 * @return array{object: File|Folder, boards: BaseObject[], sendBoardAccessAnalytics: bool}
	 */
	private function prepareRightsChangeBackgroundJobs(
		File|Folder $object,
		Disk\Security\SecurityContext $securityContext,
		bool $shouldSendBoardAccessAnalytics = false,
	): array
	{
		$boards = [];
		if ($object instanceof Folder)
		{
			$objectsInFolder = $object->getDescendants($securityContext, [
				'filter' => [
					'=TYPE' => Disk\Internals\ObjectTable::TYPE_FILE,
					'@TYPE_FILE' => [TypeFile::FLIPCHART, TypeFile::DOCUMENT, TypeFile::PDF],
				],
			]);
			foreach ($objectsInFolder as $descObject)
			{
				$realObject = $descObject->getRealObject();
				if ($realObject instanceof BaseObject)
				{
					$boards[] = $realObject;
				}
			}
		}

		return [
			'object' => $object,
			'boards' => $boards,
			'sendBoardAccessAnalytics' => $shouldSendBoardAccessAnalytics,
		];
	}

	/** @param array{object: File|Folder, boards: BaseObject[], sendBoardAccessAnalytics: bool} $payload */
	private function scheduleRightsChangeBackgroundJobs(array $payload): void
	{
		if ($payload['sendBoardAccessAnalytics'])
		{
			Application::getInstance()->addBackgroundJob(static function (): void {
				$event = new AnalyticsEvent('give_access', 'boards', 'boards');
				$event->send();
			});
		}

		$object = $payload['object'];
		Application::getInstance()->addBackgroundJob(
			function () use ($object): void {
				$this->terminateSessionsWithInsufficientRights($object);
			},
		);

		$boards = $payload['boards'];
		if ($boards !== [])
		{
			Application::getInstance()->addBackgroundJob(
				function () use ($boards): void {
					foreach ($boards as $board)
					{
						$this->terminateSessionsWithInsufficientRights($board);
					}
				},
			);
		}
	}

	/**
	 * Sends JSON response and terminates controller.
	 * @param mixed $response
	 * @param null|array $params
	 * @return void
	 */
	protected function sendJsonResponse($response, $params = null)
	{
		if(!defined('PUBLIC_AJAX_MODE'))
		{
			define('PUBLIC_AJAX_MODE', true);
		}

		$this->restartBuffer();

		if(!empty($params['http_status']) && $params['http_status'] == 403)
		{
			header('HTTP/1.0 403 Forbidden', true, 403);
		}
		if(!empty($params['http_status']) && $params['http_status'] == 500)
		{
			header('HTTP/1.0 500 Internal Server Error', true, 500);
		}
		if(!empty($params['http_status']) && $params['http_status'] == 510)
		{
			header('HTTP/1.0 510 Not Extended', true, 510);
		}

		header('Content-Type:application/json; charset=UTF-8');
		echo Json::encode($response);

		$this->end();
	}

	/**
	 * Sends JSON response with status "error" and with errors and terminates controller.
	 * @param array $response Data to response.
	 * @return void
	 */
	protected function sendJsonErrorResponse(array $response = array())
	{
		$errors = array();
		foreach($this->getErrors() as $error)
		{
			/** @var Error $error */
			$errors[] = array(
				'message' => $error->getMessage(),
				'code' => $error->getCode(),
			);
		}
		unset($error);
		$response['status'] = Disk\Internals\Controller::STATUS_ERROR;
		$response['errors'] = $errors;
		$this->sendJsonResponse($response);
	}

	final protected function restartBuffer()
	{
		global $APPLICATION;
		$APPLICATION->restartBuffer();
	}

	/**
	 * Terminates controller and application.
	 * This method replaces "die()" or "exit()" and ensures life cycle of application.
	 * @return void
	 */
	protected function end()
	{
		$this->logDebugInfo();

		/** @noinspection PhpUndefinedClassInspection */
		\CMain::finalActions();
		die;
	}

	/**
	 * Sends JSON response with status "denied" and terminates controller.
	 * @param string $message Message.
	 * @return void
	 */
	protected function sendJsonAccessDeniedResponse($message = '')
	{
		$this->sendJsonResponse(array(
			'status' => Disk\Internals\Controller::STATUS_DENIED,
			'message' => $message,
		));
	}

	/**
	 * Sends JSON response with status "success" and mixed data, and terminates controller.
	 * @param array $response Data to response.
	 * @return void
	 */
	protected function sendJsonSuccessResponse(array $response = array())
	{
		$response['status'] = Disk\Internals\Controller::STATUS_SUCCESS;
		$this->sendJsonResponse($response);
	}

	protected function isObjectEditable(BaseObject $baseObject): bool
	{
		return
			$baseObject instanceof File
			&& DocumentHandler::isEditable($baseObject->getExtension())
		;
	}

	protected function checkManageMembersAccess(BaseObject $baseObject): ?MembersAccessDisableReason
	{
		if ($baseObject instanceof Folder)
		{
			if (!Bitrix24Manager::isFeatureEnabled('disk_folder_sharing'))
			{
				return MembersAccessDisableReason::Feature;
			}

			return null;
		}

		if ($baseObject instanceof File)
		{
			$isBoardType = (int)$baseObject->getTypeFile() === TypeFile::BOARD;

			if ($isBoardType)
			{
				return null;
			}

			if (!Bitrix24Manager::isFeatureEnabled('disk_file_sharing'))
			{
				return MembersAccessDisableReason::Feature;
			}

			return null;
		}

		return null;
	}

	protected function checkManageExternalLink(
		BaseObject $baseObject,
		Disk\Security\SecurityContext $securityContext,
	): ?ExternalLinkDisableReason
	{
		if ($baseObject instanceof Folder)
		{
			$featureName = 'disk_manual_external_folder';
		}
		elseif ($baseObject instanceof File)
		{
			$isBoardType = (int)$baseObject->getTypeFile() === TypeFile::BOARD;
			$featureName = $isBoardType ? 'disk_board_external_link' : 'disk_manual_external_link';
		}
		else
		{
			$featureName = null;
		}

		if (is_string($featureName) && !Bitrix24Manager::isFeatureEnabled($featureName))
		{
			return ExternalLinkDisableReason::Feature;
		}

		if (!Configuration::isPossibleToShowExternalLinkControl())
		{
			return ExternalLinkDisableReason::Option;
		}

		if (!$baseObject->canManageExternalLink($securityContext))
		{
			return ExternalLinkDisableReason::ObjectOption;
		}

		return null;
	}

	/**
	 * @param mixed $value
	 * @return bool|null
	 */
	protected function toBoolOrNull(mixed $value): ?bool
	{
		return match ($value)
		{
			'true' => true,
			'false' => false,
			default => null,
		};
	}
}
