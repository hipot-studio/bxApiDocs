<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\Mixins\ManualStartDocumentTrait;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Crm\Integration\BizProc\Activity\Mixins\EventOutputsTrait;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('ManualStartTrigger'))
{
	return;
}

if (!\Bitrix\Main\Loader::includeModule('crm'))
{
	return;
}

class CBPCrmContactManualStartTrigger extends \CBPManualStartTrigger
{
	use EventOutputsTrait;
	use ManualStartDocumentTrait;

	private const EVENT_INITIATOR_ID = 'Initiator';
	private const EVENT_DATE_TIME_ID = 'EventDateTime';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->initManualStartDocumentProperties();

		$this->arProperties[self::EVENT_INITIATOR_ID] = null;
		$this->arProperties[self::EVENT_DATE_TIME_ID] = null;

		$this->setPropertiesTypes([
			self::EVENT_INITIATOR_ID => ['Type' => FieldType::USER],
			self::EVENT_DATE_TIME_ID => ['Type' => FieldType::DATETIME],
		]);
	}

	public function execute(): int
	{
		$initiatorUserId = $this->resolveEventInitiatorUserId();
		$this->setProperties([
			static::getReturnDocumentFieldName() => $this->getDocumentId(),
			...static::buildEventOutputs($initiatorUserId),
		]);
		$this->setPropertiesTypes([
			static::getReturnDocumentFieldName() => $this->getReturnDocumentMapTypeForInstance(),
		]);

		return CBPActivityExecutionStatus::Closed;
	}

	protected static function resolveDocumentType(): ?array
	{
		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return null;
		}

		return CCrmBizProcHelper::ResolveDocumentType(CCrmOwnerType::Contact);
	}

	protected function getSection(): ?Section
	{
		return new Section(static::getModuleId() . '|' . static::getDocument());
	}

	protected static function getModuleId(): string
	{
		return 'crm';
	}

	protected static function getDocument():string
	{
		return 'CONTACT';
	}
}
