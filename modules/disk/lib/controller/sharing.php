<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Internal\Service\Audit\AuditActionContextResolver;
use Bitrix\Disk\Internal\Service\Audit\AuditCaptureService;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContextStack;
use Bitrix\Disk\Internal\Service\Audit\AuditRightsChangeAuditService;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Localization\Loc;

final class Sharing extends Engine\Controller
{
	public function getPrimaryAutoWiredParameter()
	{
		return new ExactParameter(Disk\Sharing::class, 'sharing', function($className, $id){
			return Disk\Sharing::loadById($id);
		});
	}

	public function deleteAction(Disk\Sharing $sharing)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$baseObject = $sharing->getRealObject();
		if (!$baseObject)
		{
			$this->errorCollection[] = new Error('Could not find object');

			return;
		}

		$securityContext = $baseObject->getStorage()->getSecurityContext($currentUserId);
		if (!$baseObject->canChangeRights($securityContext))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return;
		}

		AuditTransaction::run(function (AuditTransaction $transaction) use ($sharing, $currentUserId, $baseObject): bool {
			$auditService = new AuditRightsChangeAuditService();
			$before = $auditService->capture($baseObject);
			if (!$sharing->delete($currentUserId, true, $transaction))
			{
				$this->errorCollection->add($sharing->getErrors());

				return false;
			}
			$this->scheduleRightsAudit($baseObject, $before, $currentUserId, $transaction, $auditService);

			return true;
		});
	}

	public function changeTaskNameAction(Disk\Sharing $sharing, $newTaskName)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$baseObject = $sharing->getRealObject();
		if (!$baseObject)
		{
			$this->errorCollection[] = new Error('Could not find object');

			return;
		}

		$rightsManager = Disk\Driver::getInstance()->getRightsManager();
		$securityContext = $baseObject->getStorage()->getSecurityContext($currentUserId);
		if (!$baseObject->canShare($securityContext) || !$rightsManager->isValidTaskName($newTaskName))
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return;
		}

		$maxTaskName = $rightsManager->getPseudoMaxTaskByObjectForUser($baseObject, $currentUserId);
		if ($rightsManager->pseudoCompareTaskName($newTaskName, $maxTaskName) > 0)
		{
			$this->errorCollection[] = new Error(Loc::getMessage('DISK_ERROR_MESSAGE_DENIED'));

			return;
		}

		$rightsManager = Disk\Driver::getInstance()->getRightsManager();

		$domain = $rightsManager->getSharingDomain($sharing->getId());
		$realObject = $baseObject->getRealObject();

		// one transaction, so that the journal of sync compares the targets once and sees the role change
		AuditTransaction::run(function (AuditTransaction $transaction) use (
			$rightsManager,
			$realObject,
			$domain,
			$sharing,
			$newTaskName,
			$currentUserId,
		): bool {
			$auditService = new AuditRightsChangeAuditService();
			$before = $auditService->capture($realObject);
			if (!$rightsManager->deleteByDomain($realObject, $domain, $transaction))
			{
				$this->errorCollection->add($rightsManager->getErrors());

				return false;
			}

			if (!$sharing->changeTaskName($newTaskName))
			{
				$this->errorCollection->add($sharing->getErrors());

				return false;
			}

			$newRights = [
				[
					'ACCESS_CODE' => $sharing->getToEntity(),
					'TASK_ID' => $rightsManager->getTaskIdByName($sharing->getTaskName()),
					'DOMAIN' => $domain,
				]
			];

			if (!$rightsManager->append($realObject, $newRights, $transaction))
			{
				$this->errorCollection->add($rightsManager->getErrors());

				return false;
			}
			$this->scheduleRightsAudit($realObject, $before, $currentUserId, $transaction, $auditService);

			return true;
		});
	}

	private function scheduleRightsAudit(
		Disk\BaseObject $object,
		?array $before,
		?int $currentUserId,
		AuditTransaction $transaction,
		AuditRightsChangeAuditService $auditService,
	): void
	{
		AuditCaptureService::runFailOpen(static function () use (
			$object, $before, $currentUserId, $transaction, $auditService,
		): \Bitrix\Main\Result {
			$context = AuditOperationContext::create(
				operationId: AuditOperationContextStack::current()?->operationId,
				actionContext: (new AuditActionContextResolver())->resolveForWeb($currentUserId),
			);

			return $auditService->schedule($object, $before, $context, $transaction);
		});
	}
}
