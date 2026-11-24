<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

enum OpenRefusalReason: string
{
	case ReadDenied = 'read_denied';
	case EditDenied = 'edit_denied';
	case AttachmentMismatch = 'attachment_mismatch';
	case AttachmentFileUnavailable = 'attachment_file_unavailable';
	case LinkContextMismatch = 'link_context_mismatch';
	case LinkAccessUnavailable = 'link_access_unavailable';
	case ShortLinkUnavailable = 'short_link_unavailable';
	case LinkPasswordUnconfirmed = 'link_password_unconfirmed';
	case LinkEditModeDenied = 'link_edit_mode_denied';
	case AccessRevoked = 'access_revoked';

	case SessionMissing = 'session_missing';
	case SessionInactive = 'session_inactive';
	case SessionServiceMismatch = 'session_service_mismatch';
	case FileMissing = 'file_missing';
	case ContentUnavailable = 'content_unavailable';
	case ShortLinkFileMismatch = 'short_link_file_mismatch';
	case ShortLinkVersionMismatch = 'short_link_version_mismatch';
	case ViewSessionUnavailable = 'view_session_unavailable';
	case ViewModePersistFailed = 'view_mode_persist_failed';
	case ViewSessionReloadFailed = 'view_session_reload_failed';
	case FileTooLarge = 'file_too_large';
	case SessionChanged = 'session_changed';

	case RequestBuildFailed = 'request_build_failed';
	case RegistrationFailed = 'registration_failed';
	case RequestBindingFailed = 'request_binding_failed';
	case SendAdmissionFailed = 'send_admission_failed';
	case DeadlineExceeded = 'deadline_exceeded';

	public function stage(): string
	{
		return match ($this)
		{
			self::ReadDenied, self::EditDenied, self::AttachmentMismatch, self::AttachmentFileUnavailable,
			self::LinkContextMismatch, self::LinkAccessUnavailable, self::ShortLinkUnavailable,
			self::LinkPasswordUnconfirmed, self::LinkEditModeDenied, self::AccessRevoked => 'access',
			self::SessionMissing, self::SessionInactive, self::SessionServiceMismatch, self::FileMissing,
			self::ContentUnavailable, self::ShortLinkFileMismatch, self::ShortLinkVersionMismatch,
			self::ViewSessionUnavailable, self::ViewModePersistFailed, self::ViewSessionReloadFailed,
			self::FileTooLarge, self::SessionChanged => 'session_content',
			self::RequestBuildFailed, self::RegistrationFailed, self::RequestBindingFailed,
			self::SendAdmissionFailed, self::DeadlineExceeded => 'request_preparation',
		};
	}
}
