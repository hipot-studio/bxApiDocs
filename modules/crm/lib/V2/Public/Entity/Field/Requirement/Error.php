<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Field\Requirement;

/**
 * The closed set of expected refusals reported while processing a field requirement.
 */
enum Error: string
{
	case ACCESS_DENIED = 'ACCESS_DENIED';
	case ENTITY_TYPE_NOT_SUPPORTED = 'ENTITY_TYPE_NOT_SUPPORTED';
	case ENTITY_TYPE_NOT_FOUND = 'ENTITY_TYPE_NOT_FOUND';
	case CATEGORY_ID_REQUIRED = 'CATEGORY_ID_REQUIRED';
	case CATEGORY_ID_NOT_ALLOWED = 'CATEGORY_ID_NOT_ALLOWED';
	case CATEGORY_ID_INVALID = 'CATEGORY_ID_INVALID';
	case CATEGORY_NOT_FOUND = 'CATEGORY_NOT_FOUND';
	case CATEGORY_ENTITY_TYPE_MISMATCH = 'CATEGORY_ENTITY_TYPE_MISMATCH';
	case FIELD_NOT_FOUND = 'FIELD_NOT_FOUND';
	case CONFIGURATION_CONFLICT = 'CONFIGURATION_CONFLICT';
	case FIELD_REQUIREMENT_NOT_SUPPORTED = 'FIELD_REQUIREMENT_NOT_SUPPORTED';
	case REQUIRED_STAGE_IDS_NULL = 'REQUIRED_STAGE_IDS_NULL';
	case REQUIRED_STAGE_ID_INVALID = 'REQUIRED_STAGE_ID_INVALID';
	case STAGES_NOT_SUPPORTED = 'STAGES_NOT_SUPPORTED';
	case STAGE_NOT_FOUND = 'STAGE_NOT_FOUND';
	case STAGE_SELECTION_NOT_REPRESENTABLE = 'STAGE_SELECTION_NOT_REPRESENTABLE';
	case STAGE_REQUIREMENT_NOT_AVAILABLE = 'STAGE_REQUIREMENT_NOT_AVAILABLE';
	case WRITE_FAILED = 'WRITE_FAILED';

	/**
	 * Returns the stable machine-readable error code.
	 */
	public function getCode(): string
	{
		return $this->value;
	}

	/**
	 * Returns a safe result error with the stable domain code.
	 */
	public function toError(): \Bitrix\Main\Error
	{
		return new \Bitrix\Main\Error($this->value, $this->getCode());
	}
}
