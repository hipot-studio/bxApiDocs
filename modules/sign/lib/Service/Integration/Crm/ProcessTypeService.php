<?php

namespace Bitrix\Sign\Service\Integration\Crm;

use Bitrix\Crm;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Config\Feature;

Loc::loadMessages(__FILE__);

/**
 * Smart process the KEDO processes of the portal are run on. The type is created while the feature
 * is enabled, so a portal with the feature closed keeps no extra entity in CRM.
 */
final class ProcessTypeService
{
	public const TYPE_CODE = 'SIGN_KEDO_PROCESS';

	private const TYPE_NAME = 'SignKedoProcess';

	private ?int $entityTypeId = null;
	private bool $isEntityTypeIdResolved = false;

	public function isAvailable(): bool
	{
		return $this->getEntityTypeId() !== null;
	}

	public function getEntityTypeId(): ?int
	{
		if ($this->isEntityTypeIdResolved)
		{
			return $this->entityTypeId;
		}

		if (!$this->isFeatureEnabled() || !$this->isCrmAvailable())
		{
			return null;
		}

		$entityTypeId = $this->findEntityTypeId();
		if ($entityTypeId === null)
		{
			$this->createIfNotExists();
		}
		else
		{
			$this->rememberEntityTypeId($entityTypeId);
		}

		return $this->entityTypeId;
	}

	public function createIfNotExists(): Main\Result
	{
		$result = new Main\Result();

		if (!$this->isFeatureEnabled())
		{
			return $result->addError(new Main\Error('Feature is disabled'));
		}

		if (!$this->isCrmAvailable())
		{
			return $result->addError(new Main\Error('Module crm is not available'));
		}

		if (!$this->getLock())
		{
			return $result->addError(new Main\Error('Process type is being created right now'));
		}

		try
		{
			$entityTypeId = $this->findEntityTypeId();
			if ($entityTypeId !== null)
			{
				$this->rememberEntityTypeId($entityTypeId);

				return $result;
			}

			$restriction = Crm\Restriction\RestrictionManager::getDynamicTypesLimitRestriction();
			if ($restriction->isCreateTypeRestricted())
			{
				return $result->addError($restriction->getCreateTypeRestrictedError());
			}

			return $this->createType();
		}
		finally
		{
			$this->releaseLock();
		}
	}

	/**
	 * ENTITY_TYPE_ID is left to the default value of the field: the next free identifier comes from
	 * the sequence of dynamic types, and reserved identifiers of the module types are not ours to take.
	 *
	 * The sales capabilities of a CRM item stay off: an employee of the process comes from the launch
	 * group, not from a CRM client, and a personnel process has neither a source nor tracking.
	 * Open permissions, on the other hand, stay on. The system types of signing turn them off because
	 * they carry a permission layer of their own; this one is an ordinary dynamic type without such a
	 * layer, and its default category would end up available to no role at all.
	 */
	private function createType(): Main\Result
	{
		$result = new Main\Result();

		Crm\Service\Container::getInstance()->getLocalization()->loadMessages();

		$type = Crm\Model\Dynamic\TypeTable::createObject()
			->setName(self::TYPE_NAME)
			->setTitle((string)Loc::getMessage('SIGN_INTEGRATION_CRM_PROCESS_TYPE_TITLE'))
			->setCode(self::TYPE_CODE)
			->setCreatedBy(0)
			->setIsCategoriesEnabled(true)
			->setIsStagesEnabled(true)
			->setIsBeginCloseDatesEnabled(true)
			->setIsClientEnabled(false)
			->setIsUseInUserfieldEnabled(false)
			->setIsLinkWithProductsEnabled(false)
			->setIsCrmTrackingEnabled(false)
			->setIsMycompanyEnabled(false)
			->setIsDocumentsEnabled(false)
			->setIsSourceEnabled(false)
			->setIsObserversEnabled(true)
			->setIsRecyclebinEnabled(false)
			->setIsAutomationEnabled(true)
			->setIsBizProcEnabled(true)
			->setIsPaymentsEnabled(false)
			->setIsSetOpenPermissions(true)
		;

		$saveResult = $type->save();
		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		$this->rememberEntityTypeId($type->getEntityTypeId());

		return $result;
	}

	/**
	 * Only an initialized type counts as existing: an uninitialized row means that the creation is
	 * running right now or that it broke halfway, and processes cannot be run on such a type.
	 */
	private function findEntityTypeId(): ?int
	{
		$row = Crm\Model\Dynamic\TypeTable::getList([
			'select' => ['ENTITY_TYPE_ID'],
			'filter' => [
				'=CODE' => self::TYPE_CODE,
				'=IS_INITIALIZED' => true,
			],
			'limit' => 1,
		])->fetch();

		return $row ? (int)$row['ENTITY_TYPE_ID'] : null;
	}

	private function rememberEntityTypeId(?int $entityTypeId): void
	{
		$this->entityTypeId = $entityTypeId;
		$this->isEntityTypeIdResolved = true;
	}

	private function isFeatureEnabled(): bool
	{
		return Feature::instance()->isKedoRunProcessEnabled();
	}

	private function isCrmAvailable(): bool
	{
		return Main\Loader::includeModule('crm');
	}

	private function getLock(): bool
	{
		return Main\Application::getConnection()->lock($this->getLockName());
	}

	private function releaseLock(): bool
	{
		return Main\Application::getConnection()->unlock($this->getLockName());
	}

	/**
	 * The symbolic code is the key of the lock: the identifier of the type is unknown until the row
	 * is written, so two simultaneous creations can be told apart only by the code.
	 */
	private function getLockName(): string
	{
		return 'sign_process_type_' . self::TYPE_CODE;
	}
}
