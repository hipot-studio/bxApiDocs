<?php

namespace Bitrix\Sign\Type\Bizproc;

use Bitrix\Main\Localization\Loc;

/**
 * Outcome codes of the draft activity and the phrases they stand for.
 * Services of the flow return a code, the activity turns it into a phrase: that is what keeps the
 * texts in the lang file of the activity while the services know nothing about the activity.
 *
 * The codes live here and not in a constant of the activity class on purpose: the platform
 * serializes an activity as a whole, and a constant referring to a class of the module would pull
 * the autoload of that class where the autoload of sign is not registered.
 */
enum DraftActivityMessageCode: string
{
	case EMPTY_FILE = 'EMPTY_FILE';
	case VALUE_IS_MULTIPLE = 'VALUE_IS_MULTIPLE';
	case SOURCE_VALUE_MISMATCH = 'SOURCE_VALUE_MISMATCH';
	case SOURCE_CONFLICT = 'SOURCE_CONFLICT';
	case DISK_MODULE_MISSING = 'DISK_MODULE_MISSING';
	case DISK_FILE_NOT_FOUND = 'DISK_FILE_NOT_FOUND';
	case FILE_NOT_FOUND = 'FILE_NOT_FOUND';
	case UNSUPPORTED_EXTENSION = 'UNSUPPORTED_EXTENSION';
	case RESPONSIBLE_NOT_SET = 'RESPONSIBLE_NOT_SET';
	case UNAVAILABLE = 'UNAVAILABLE';
	case TARIFF_RESTRICTED = 'TARIFF_RESTRICTED';
	case LICENSE_SERVICE_UNAVAILABLE = 'LICENSE_SERVICE_UNAVAILABLE';
	case BLANK_CREATE_FAILED = 'BLANK_CREATE_FAILED';
	case DOCUMENT_CREATE_FAILED = 'DOCUMENT_CREATE_FAILED';
	case DOCUMENT_UPLOAD_FAILED = 'DOCUMENT_UPLOAD_FAILED';
	case RESPONSIBLE_ASSIGN_FAILED = 'RESPONSIBLE_ASSIGN_FAILED';
	case COMPENSATION_DONE = 'COMPENSATION_DONE';
	case RELATION_FAILED = 'RELATION_FAILED';
	case DOCUMENT_REUSED = 'DOCUMENT_REUSED';

	/**
	 * Reserve for a failure of a foreign service that carries no code of this contract.
	 * @see self::getUnknownReasonMessage()
	 */
	case UNKNOWN_REASON = 'UNKNOWN_REASON';

	public const REASON_PLACEHOLDER = '#REASON#';
	public const DOCUMENT_ID_PLACEHOLDER = '#DOCUMENT_ID#';

	public function messageId(): string
	{
		return match ($this)
		{
			self::EMPTY_FILE => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_EMPTY_FILE',
			self::VALUE_IS_MULTIPLE => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_VALUE_IS_MULTIPLE',
			self::SOURCE_VALUE_MISMATCH => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_SOURCE_VALUE_MISMATCH',
			self::SOURCE_CONFLICT => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_SOURCE_CONFLICT',
			self::DISK_MODULE_MISSING => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_DISK_MODULE_MISSING',
			self::DISK_FILE_NOT_FOUND => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_DISK_FILE_NOT_FOUND',
			self::FILE_NOT_FOUND => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_FILE_NOT_FOUND',
			self::UNSUPPORTED_EXTENSION => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_UNSUPPORTED_FILE_EXTENSION',
			self::RESPONSIBLE_NOT_SET => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_RESPONSIBLE_NOT_SET',
			self::UNAVAILABLE => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_UNAVAILABLE',
			self::TARIFF_RESTRICTED => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_TARIFF_RESTRICTED',
			self::LICENSE_SERVICE_UNAVAILABLE =>
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_LICENSE_SERVICE_UNAVAILABLE',
			self::BLANK_CREATE_FAILED => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_UNABLE_TO_CREATE_BLANK',
			self::DOCUMENT_CREATE_FAILED => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_UNABLE_TO_CREATE_DOC',
			self::DOCUMENT_UPLOAD_FAILED => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_UNABLE_TO_UPLOAD_DOC',
			self::RESPONSIBLE_ASSIGN_FAILED =>
				'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_RESPONSIBLE_FOR_SMART_DOCUMENT_NOT_SET',
			self::COMPENSATION_DONE => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_COMPENSATION_DONE',
			self::RELATION_FAILED => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_WARNING_RELATION_FAILED',
			self::DOCUMENT_REUSED => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_DOCUMENT_REUSED',
			self::UNKNOWN_REASON => 'SIGN_ACTIVITIES_SIGN_B2E_DOCUMENT_DRAFT_ERROR_UNKNOWN_REASON',
		};
	}

	/**
	 * Text of a failure a foreign service reported without a code of this contract: the phrase of
	 * the module carries the text of the service escaped, so that text never reaches the log of the
	 * process on its own. The log renders raw HTML, and those texts are written for a developer.
	 */
	public static function getUnknownReasonMessage(string $reason): string
	{
		return (string)Loc::getMessage(
			self::UNKNOWN_REASON->messageId(),
			[self::REASON_PLACEHOLDER => htmlspecialcharsbx(trim($reason))],
		);
	}
}
