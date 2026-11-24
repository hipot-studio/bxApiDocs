<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\File;
use Bitrix\Disk\Document\Flipchart\BoardSessionTerminationService;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Entity\Audit\AuditChannel;
use Bitrix\Disk\Internal\Enum\ExternalLinkDisableReason;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContextStack;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkAccessService;
use Bitrix\Disk\Internals;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\Public\Provider\ExternalLinkTariffProvider;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\UrlManager;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\Web\Uri;

abstract class BaseObject extends Internals\Engine\Controller
{
	protected ExternalLinkProvider $externalLinkProvider;
	protected ExternalLinkAccessService $externalLinkAccessService;
	protected ExternalLinkTariffProvider $externalLinkTariffProvider;

	public function __construct(?Request $request = null)
	{
		parent::__construct($request);

		$this->externalLinkProvider = ServiceLocator::getInstance()->get(ExternalLinkProvider::class);
		$this->externalLinkAccessService = ServiceLocator::getInstance()->get(ExternalLinkAccessService::class);
		$this->externalLinkTariffProvider = ServiceLocator::getInstance()->get(ExternalLinkTariffProvider::class);
	}

	protected function get(Disk\BaseObject $object)
	{
		return [
			'object' => $object->jsonSerialize(),
		];
	}

	/**
	 * Returns default pre-filters for action.
	 * @return array
	 */
	protected function getDefaultPreFilters()
	{
		$defaultPreFilters = parent::getDefaultPreFilters();
		$defaultPreFilters[] = new Internals\Engine\ActionFilter\CheckReadPermission();

		return $defaultPreFilters;
	}

	protected function getDefaultPostFilters()
	{
		$defaultPostFilters = parent::getDefaultPostFilters();
		$defaultPostFilters[] = new Internals\Engine\ActionFilter\HumanReadableError();

		return $defaultPostFilters;
	}

	protected function rename(
		Disk\BaseObject $object,
		string $newName,
		bool $autoCorrect = false,
		bool $generateUniqueName = false,
		bool $shouldCheckUnifiedLink = false,
	)
	{
		$securityContext = $object->getStorage()?->getSecurityContext(
			user: $this->getCurrentUser()->getId(),
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
		);

		if (!$securityContext || !$object->canRename($securityContext))
		{
			$this->addError(new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED')));

			return null;
		}

		if ($autoCorrect)
		{
			$newName = Disk\Ui\Text::correctFilename($newName);
		}

		if (!$object->rename($newName, $generateUniqueName))
		{
			$this->addErrors($object->getErrors());

			return null;
		}

		return $this->get($object);
	}

	protected function deleteFile(Disk\File $file)
	{
		$securityContext = $file->getStorage()->getSecurityContext($this->getCurrentUser()->getId());
		if (!$file->canDelete($securityContext))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return;
		}

		if (!$file->delete($this->getCurrentUser()->getId()))
		{
			$this->errorCollection->add($file->getErrors());
		}
	}

	protected function deleteFolder(Disk\Folder $folder)
	{
		$securityContext = $folder->getStorage()->getSecurityContext($this->getCurrentUser()->getId());
		if (!$folder->canDelete($securityContext))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return;
		}

		if (!$folder->deleteTree($this->getCurrentUser()->getId()))
		{
			$this->errorCollection->add($folder->getErrors());
		}
	}

	protected function markDeleted(
		Disk\BaseObject $object,
		bool $shouldCheckUnifiedLink = false,
	)
	{
		$securityContext = $object->getStorage()->getSecurityContext(
			user: $this->getCurrentUser()->getId(),
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
		);

		if (!$object->canMarkDeleted($securityContext))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return;
		}

		if (!$object->markDeleted($this->getCurrentUser()->getId()))
		{
			$this->errorCollection->add($object->getErrors());
		}
	}

	protected function restore(
		Disk\BaseObject $object,
		bool $shouldCheckUnifiedLink = false,
	)
	{
		$securityContext = $object->getStorage()->getSecurityContext(
			user: $this->getCurrentUser()->getId(),
			shouldCheckUnifiedLink: $shouldCheckUnifiedLink,
		);

		if (!$object->canRestore($securityContext))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return;
		}

		if (!$object->restore($this->getCurrentUser()->getId()))
		{
			$this->errorCollection->add($object->getErrors());

			return;
		}
		$this->addErrorsOfRestoredObject($object);

		//@see \Bitrix\Disk\FileLink::restore and \Bitrix\Disk\BaseObject::restoreByLinkObject
		//in this case the object which we want to restore is original. We can't get symlink now.
		return $this->get(
			$object->getRealObject(),
		);
	}

	/**
	 * A folder is restored even when the format policy keeps some files under it in the trash can:
	 * the refusals stay with the folder, or with the object a link restored, and go to the user
	 * beside the restored folder. A link answers with the errors of that object itself.
	 */
	protected function addErrorsOfRestoredObject(Disk\BaseObject $object): void
	{
		$this->errorCollection->add($object->getErrors());
	}

	protected function copyTo(Disk\BaseObject $object, Disk\Folder $toFolder)
	{
		$userId = $this->getCurrentUser()->getId();
		$securityContext = $object->getStorage()?->getSecurityContext($userId);
		$targetSecurityContext = $toFolder->getStorage()?->getSecurityContext($userId);
		if (
			!$securityContext
			|| !$targetSecurityContext
			|| !$object->canRead($securityContext)
			|| !$toFolder->canAdd($targetSecurityContext)
		)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return null;
		}

		$copiedObject = AuditOperationContextStack::runWithContext(
			AuditOperationContext::create(
				actionKind: AuditOperationContext::ACTION_EXPLICIT_COPY,
				channel: AuditChannel::WEB,
				selectedTargetIds: [(string)$object->getId()],
			),
			fn() => $object->copyTo($toFolder, $this->getCurrentUser()->getId(), true),
		);
		if (!$copiedObject)
		{
			$this->errorCollection->add($object->getErrors());

			return null;
		}

		return $this->get($copiedObject);
	}

	protected function move(Disk\BaseObject $object, Disk\Folder $toFolder)
	{
		$userId = $this->getCurrentUser()->getId();
		$securityContext = $object->getStorage()?->getSecurityContext($userId);
		//the right to add belongs to the target storage, the rest of the composite right - to the source one
		$targetSecurityContext = $toFolder->getRealObject()?->getStorage()?->getSecurityContext($userId);
		if (
			!$securityContext
			|| !$targetSecurityContext
			|| !$object->canRead($securityContext)
			|| !$object->canMarkDeleted($securityContext)
			|| !$targetSecurityContext->canAdd($toFolder->getRealObjectId())
		)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return;
		}

		$movedObject = AuditOperationContextStack::runWithContext(
			AuditOperationContext::create(
				actionKind: $object->getStorageId() == $toFolder->getRealObject()->getStorageId()
					? AuditOperationContext::ACTION_EXPLICIT_MOVE
					: AuditOperationContext::ACTION_CROSS_STORAGE_MOVE,
				channel: AuditChannel::WEB,
				selectedTargetIds: [(string)$object->getId()],
			),
			fn() => $object->moveTo($toFolder, $this->getCurrentUser()->getId(), true),
		);
		if (!$movedObject)
		{
			$this->errorCollection->add($object->getErrors());

			return;
		}

		return $this->get($movedObject);
	}

	protected function generateExternalLink(Disk\BaseObject $object)
	{
		if (!$this->externalLinkAccessService->isExternalLinkEnabledOnPortal())
		{
			$this->errorCollection[] = new Error(
				message: Loc::getMessage('DISK_ERROR_MESSAGE_EXTERNAL_LINK_DISABLED_ON_PORTAL'),
				customData: [
					'reason' => ExternalLinkDisableReason::Option,
				],
			);

			return null;
		}

		if (!$this->canManageExternalLink($object))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return null;
		}

		$extLink = $this->getExternalLinkObject($object);
		if (!$extLink)
		{
			if (!$this->externalLinkTariffProvider->isManualLinkCreationAllowed($object))
			{
				$this->errorCollection[] = new Error(
					message: Loc::getMessage('DISK_ERROR_MESSAGE_EXTERNAL_LINK_DISABLED_BY_TARIFF'),
					customData: [
						'reason' => ExternalLinkDisableReason::Feature,
					],
				);

				return null;
			}

			$extLink = $object->getRealObject()->addExternalLink([
				'CREATED_BY' => $this->getCurrentUser()->getId(),
				'TYPE' => ExternalLink::TYPE_MANUAL,
			]);
		}

		if (!$extLink)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_FILE_C_ERROR_COULD_NOT_CREATE_FIND_EXT_LINK'));

			return null;
		}

		if ($object->getType() == Internals\ObjectTable::TYPE_FILE && $object->getRealObject()->getTypeFile() == TypeFile::FLIPCHART)
		{
			Application::getInstance()->addBackgroundJob(function() {
				$event = new AnalyticsEvent('turnon_publiclink', 'boards', 'boards');
				$event->send();
			});
		}

		return $this->parseExternalLinkObject($extLink, $object);
	}

	protected function getExternalLink(Disk\BaseObject $object): ?array
	{
		$extLink = $this->getExternalLinkObject($object);

		if (!$extLink)
		{
			return null;
		}

		return $this->parseExternalLinkObject($extLink, $object);
	}

	protected function disableExternalLink(Disk\BaseObject $object)
	{
		if (!$this->canManageExternalLink($object))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return false;
		}

		$extLink = $this->getExternalLinkObject($object);
		if (!$extLink)
		{
			return true;
		}

		$externalLinkId = (int)$extLink->getId();
		if ($extLink->delete())
		{
			$file = $object->getRealObject();
			if ($file instanceof File && $file->getTypeFile() == TypeFile::FLIPCHART)
			{
				$service = new BoardSessionTerminationService($file);
				$service->terminateSessionsByExternalLink($externalLinkId);
			}

			return true;
		}

		return false;
	}

	/**
	 * @param Disk\BaseObject $object
	 * @return bool
	 */
	protected function canManageExternalLink(Disk\BaseObject $object): bool
	{
		return $this->externalLinkAccessService->canManageExternalLink($object);
	}

	/**
	 * @param Disk\BaseObject $object
	 *
	 * @return Disk\ExternalLink|null
	 */
	private function getExternalLinkObject(Disk\BaseObject $object): ?Disk\ExternalLink
	{
		return $this->externalLinkProvider->getForUse($object->getRealObjectId());
	}

	private function parseExternalLinkObject(Disk\ExternalLink $extLink, Disk\BaseObject $object): array
	{
		$driver = Driver::getInstance();
		$link = new Uri($driver->getUrlManager()->getCopyablePublicExternalLinkForConsumer(
			$extLink,
			UrlManager::PUBLIC_LINK_CONSUMER_UNMARKED_COPY,
			$object,
		));

		$isBoard = false;
		$canEditDocument = null;
		$availableEdit = $extLink->availableEdit();
		if ($availableEdit)
		{
			$canEditDocument = $extLink->getAccessRight() === $extLink::ACCESS_RIGHT_EDIT;
			if ($object instanceof Disk\File)
			{
				$fileType = (int)$object->getTypeFile();
				$isBoard = $fileType === TypeFile::FLIPCHART;
			}
		}

		return [
			'externalLink' => [
				'id' => $extLink->getId(),
				'objectId' => $extLink->getObjectId(),
				'hash' => $extLink->getHash(),
				'link' => $link,
				'hasPassword' => $extLink->hasPassword(),
				'hasDeathTime' => $extLink->hasDeathTime(),
				'availableEdit' => $availableEdit,
				'canEditDocument' => $canEditDocument,
				'canEditSettings' => $extLink->canEditSettings(),
				'deathTime' => $extLink->getDeathTime(),
				'deathTimeTimestamp' => $extLink->hasDeathTime() ? $extLink->getDeathTime()->getTimestamp() : null,
				'isBoard' => $isBoard,
			],
		];
	}

	protected function getAllowedOperationsRights(
		Disk\BaseObject $object,
		bool $shouldCheckUnifiedLink = false,
	)
	{
		$userId = $this->getCurrentUser()->getId();

		$rightsManager = Disk\Driver::getInstance()->getRightsManager();
		$securityContext = $object->getStorage()->getSecurityContext($userId);
		if ($securityContext instanceof Disk\Security\FakeSecurityContext)
		{
			$operations = $rightsManager->listOperations();
		}
		else
		{
			$operations = $rightsManager->getUserOperationsByObject(
				$object->getRealObjectId(),
				$userId,
				$shouldCheckUnifiedLink,
			);
		}

		return [
			'operations' => $operations,
		];
	}
}
