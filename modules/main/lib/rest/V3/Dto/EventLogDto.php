<?php

namespace Bitrix\Main\Rest\V3\Dto;

use Bitrix\Main\EventLog\Internal\EventLogTable;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Rest\V3\Controller\EventLog;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\ResolvedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[OrmEntity(EventLogTable::class)]
#[ResolvedBy(EventLog::class)]
class EventLogDto extends Dto
{
	#[Filterable, Sortable]
	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_ID_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_ID_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?int $id;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_TIMESTAMP_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_TIMESTAMP_DESCRIPTION', phraseSrcFile: __FILE__))]
	public DateTime $timestampX;

	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_SEVERITY_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_SEVERITY_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?string $severity;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_AUDIT_TYPE_ID_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_AUDIT_TYPE_ID_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?string $auditTypeId;

	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_MODULE_ID_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_MODULE_ID_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?string $moduleId;

	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_ITEM_ID_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_ITEM_ID_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?string $itemId;


	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_REMOTE_ADDR_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_REMOTE_ADDR_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?string $remoteAddr;


	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_USER_AGENT_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_USER_AGENT_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?string $userAgent;


	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_REQUEST_URI_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_REQUEST_URI_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?string $requestUri;

	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_SITE_ID_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_SITE_ID_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?string $siteId;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_USER_ID_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_USER_ID_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?int $userId;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_GUEST_ID_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_GUEST_ID_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?int $guestId;

	#[Title(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_DESCRIPTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code:'REST_V3_DTO_EVENTLOG_FIELD_DESCRIPTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	public ?string $description;
}