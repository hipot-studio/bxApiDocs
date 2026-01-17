<?php

namespace Bitrix\Sign\Type\Integration\Rest;

use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\MemberStatus;

/**
 * List of document and members states that used in responses for /rest/... methods of module sign
 */
final class RestDocumentStatus
{
	const DOCUMENT_STATE_NEW = DocumentStatus::NEW ;
	const DOCUMENT_STATE_SIGNING = DocumentStatus::SIGNING;
	const DOCUMENT_STATE_STOPPED = DocumentStatus::STOPPED;
	const DOCUMENT_STATE_DONE = DocumentStatus::DONE;
	const DOCUMENT_STATE_ERROR = 'error';

	const MEMBER_STATE_WAIT = 'wait';
	const MEMBER_STATE_READY = 'ready';
	const MEMBER_STATE_DONE = 'done';
	const MEMBER_STATE_REFUSED = 'refused';
	const MEMBER_STATE_STOPPED = 'stopped';

	const STATE_UNDEFINED = 'undefined';
	
	const REST_DOCUMENT_STATUS_MAP = [
		DocumentStatus::NEW => self::DOCUMENT_STATE_NEW,
		DocumentStatus::UPLOADED => self::DOCUMENT_STATE_NEW,
		DocumentStatus::READY => self::DOCUMENT_STATE_NEW,
		DocumentStatus::SIGNING => self::DOCUMENT_STATE_SIGNING,
		DocumentStatus::STOPPED => self::DOCUMENT_STATE_STOPPED,
		DocumentStatus::DONE => self::DOCUMENT_STATE_DONE,
	];

	const REST_MEMBER_STATUS_MAP = [
		MemberStatus::WAIT => self::MEMBER_STATE_WAIT,
		MemberStatus::READY => self::MEMBER_STATE_READY,
		MemberStatus::REFUSED => self::MEMBER_STATE_REFUSED,
		MemberStatus::STOPPED => self::MEMBER_STATE_STOPPED,
		MemberStatus::STOPPABLE_READY => self::MEMBER_STATE_READY,
		MemberStatus::PROCESSING => self::MEMBER_STATE_READY,
		MemberStatus::DONE => self::MEMBER_STATE_DONE,
	];

	public static function getDocumentStatusCode(string $documentStatusCode): string
	{
		return self::REST_DOCUMENT_STATUS_MAP[$documentStatusCode] ?? self::STATE_UNDEFINED;
	}

	public static function getDocumentStatusName(string $documentStatusCode, ?string $language = null, bool $isError = false): string
	{
		$restState = self::getDocumentStatusCode($documentStatusCode);
		if ($isError)
		{
			return Loc::GetMessage(code: 'SIGN_DOCUMENT_STATUS_ERROR_NAME', language: $language) ?? '';
		}

		return match ($restState) {
			self::DOCUMENT_STATE_NEW => Loc::GetMessage(code: 'SIGN_DOCUMENT_STATUS_NEW_NAME', language: $language) ?? '',
			self::DOCUMENT_STATE_STOPPED => Loc::GetMessage(code: 'SIGN_DOCUMENT_STATUS_STOPPED_NAME', language: $language) ?? '',
			self::DOCUMENT_STATE_SIGNING => Loc::GetMessage(code: 'SIGN_DOCUMENT_STATUS_SIGNING_NAME', language: $language) ?? '',
			self::DOCUMENT_STATE_DONE => Loc::GetMessage(code: 'SIGN_DOCUMENT_STATUS_DONE_NAME', language: $language) ?? '',
			default => ucfirst($documentStatusCode),
		};
	}

	public static function getDocumentMemberStatusCode(string $memberStatusCode): string
	{
		return self::REST_MEMBER_STATUS_MAP[$memberStatusCode] ?? self::STATE_UNDEFINED;
	}

	public static function getDocumentMemberStatusName(string $memberStatusCode, ?string $language = null): string
	{
		$restState = self::getDocumentMemberStatusCode($memberStatusCode);
		return match ($restState) {
			self::MEMBER_STATE_WAIT => Loc::GetMessage(code: 'SIGN_MEMBER_STATUS_WAIT_NAME', language: $language) ?? '',
			self::MEMBER_STATE_READY => Loc::GetMessage(code: 'SIGN_MEMBER_STATUS_READY_NAME', language: $language) ?? '',
			self::MEMBER_STATE_REFUSED => Loc::GetMessage(code: 'SIGN_MEMBER_STATUS_REFUSED_NAME', language: $language) ?? '',
			self::MEMBER_STATE_STOPPED => Loc::GetMessage(code: 'SIGN_MEMBER_STATUS_STOPPED_NAME', language: $language) ?? '',
			self::MEMBER_STATE_DONE => Loc::GetMessage(code: 'SIGN_MEMBER_STATUS_DONE_NAME', language: $language) ?? '',
			default => ucfirst($memberStatusCode),
		};
	}

}
