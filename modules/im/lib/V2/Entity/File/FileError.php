<?php

namespace Bitrix\Im\V2\Entity\File;

use Bitrix\Im\V2\Error;
use Bitrix\Main\Localization\Loc;

class FileError extends Error
{
	public const DISK_NOT_INSTALLED = 'DISK_NOT_INSTALLED';
	public const ACCESS_ERROR = 'FILE_ACCESS_ERROR';
	public const STORAGE_NOT_FOUND = 'STORAGE_NOT_FOUND';
	public const FOLDER_NOT_FOUND = 'FOLDER_NOT_FOUND';
	public const COPY_ERROR = 'COPY_ERROR';
	public const STORAGE_QUOTA_EXCEEDED = 'STORAGE_QUOTA_EXCEEDED';
	public const UNKNOWN_FILE_SUBTYPE = 'UNKNOWN_FILE_SUBTYPE';
	public const NOT_FOUND = 'FILE_NOT_FOUND';
	public const CREATE_SYMLINK = 'CREATE_SYMLINK_ERROR';
	public const SAVE_BEFORE_MIGRATION_ERROR = 'FILE_SAVE_BEFORE_MIGRATION_ERROR';
	public const UPLOAD_FILE_INCORRECT_FOLDER = 'UPLOAD_FILE_INCORRECT_FOLDER';
	public const UPLOAD_FILE_INCORRECT_AUTHOR = 'UPLOAD_FILE_INCORRECT_AUTHOR';
	public const FILE_SIZE_EXCEEDED = 'FILE_SIZE_EXCEEDED';
	public const ACCESS_DENIED = 'FILE_ACCESS_DENIED';
	public const SEND_ACCESS_DENIED = 'FILE_SEND_ACCESS_DENIED';
	public const WRONG_CHAT = 'FILE_WRONG_CHAT';
	public const PREPARE_FAILED = 'FILE_PREPARE_FAILED';
	public const LIMIT_EXCEEDED = 'FILE_LIMIT_EXCEEDED';
	public const BLOCK_FILE = 'FILE_BLOCK_FILE';
	public const NOT_EDITABLE_MESSAGE_TYPE = 'FILE_NOT_EDITABLE_MESSAGE_TYPE';
	public const WRONG_COMPOSITION = 'FILE_WRONG_COMPOSITION';
	public const VIDEO_NOTE_DOWNLOAD_RESTRICTED = 'VIDEO_NOTE_DOWNLOAD_RESTRICTED';
	/**
	 * Both are contract violations by the caller of im.v2.File.directDownload, not by the person behind it:
	 * a type outside the enum and a call made inside a REST batch. A correct client reaches neither, and
	 * both are left without a phrase for the same reason as UPDATE_STEP_OUT_OF_ORDER below.
	 *
	 * BATCH_NOT_ALLOWED is shared: im.v2.File.upload and im.v2.File.uploadToFolder raise it for the batch
	 * form of fields.files called inside a REST batch, where one request would otherwise multiply into
	 * hundreds of uploads. A correct client can meet it there - it is the composition that is refused,
	 * not the call - so the answer carries the code as its own description, and both REST pages say so.
	 */
	public const UNKNOWN_DOWNLOAD_TYPE = 'FILE_UNKNOWN_DOWNLOAD_TYPE';
	public const BATCH_NOT_ALLOWED = 'FILE_BATCH_NOT_ALLOWED';

	/**
	 * Internal guard against a step of the file scenario of an update called out of turn. Unreachable while
	 * UpdateService keeps the steps in order, and left without a phrase: a person can do nothing about it.
	 */
	public const UPDATE_STEP_OUT_OF_ORDER = 'FILE_UPDATE_STEP_OUT_OF_ORDER';
	public const FEATURE_DISABLED = 'FEATURE_DISABLED';

	/**
	 * The refusals of the chunked upload door. All of them are addressed to a person behind a client
	 * that can act on them - send the chunk again, resume from the offset the answer names, start the
	 * upload over - so all of them carry a phrase.
	 *
	 * The door never lets a code of \Bitrix\UI\FileUploader\UploaderError out; the translation of the
	 * circuit into these codes lives in Upload\UploadErrorMapper.
	 */
	public const UPLOAD_CHUNK_WINDOW_EMPTY = 'FILE_UPLOAD_CHUNK_WINDOW_EMPTY';
	public const UPLOAD_CHUNK_MISSING = 'FILE_UPLOAD_CHUNK_MISSING';
	public const UPLOAD_CHUNK_TOO_BIG = 'FILE_UPLOAD_CHUNK_TOO_BIG';
	public const UPLOAD_CHUNK_TOO_SMALL = 'FILE_UPLOAD_CHUNK_TOO_SMALL';
	public const UPLOAD_CHUNK_OFFSET_MISMATCH = 'FILE_UPLOAD_CHUNK_OFFSET_MISMATCH';
	public const UPLOAD_SESSION_EXPIRED = 'FILE_UPLOAD_SESSION_EXPIRED';
	public const UPLOAD_SESSION_BUSY = 'FILE_UPLOAD_SESSION_BUSY';
	public const UPLOAD_NOT_COMPLETE = 'FILE_UPLOAD_NOT_COMPLETE';
	public const UPLOAD_TOO_MANY_SESSIONS = 'FILE_UPLOAD_TOO_MANY_SESSIONS';
	public const UPLOAD_FIELD_TOO_LONG = 'FILE_UPLOAD_FIELD_TOO_LONG';

	/**
	 * The two refusals of the half of the door that finishes an upload without publishing anything
	 * ({@see Upload\ChunkedUploadService::finishToFolder()}). They describe the state of a session to
	 * the REST client. The client handles them by code, so neither has a localized phrase.
	 *
	 * ALREADY_PUBLISHED is the one cross-mode guard of the door: a session whose file is already a message
	 * is not answered by the identifier of that file, because the caller would hand it to
	 * im.disk.file.commit and publish the very same file a second time.
	 *
	 * UPLOAD_FILE_GONE keeps the answer of the folder half whole: it carries the file or it is a refusal,
	 * and a file registered without a message goes away in the ordinary course of things - the caller
	 * discards the draft, or the cleanup of im sweeps it.
	 */
	public const UPLOAD_ALREADY_PUBLISHED = 'FILE_UPLOAD_ALREADY_PUBLISHED';
	public const UPLOAD_FILE_GONE = 'FILE_UPLOAD_FILE_GONE';

	protected function loadErrorMessage($code, $replacements): string
	{
		$postfix = '';

		if ($code === self::CREATE_SYMLINK)
		{
			$postfix = '_V3';
		}

		return Loc::getMessage("ERROR_FILE_{$code}{$postfix}", $replacements) ?: '';
	}

	protected function loadErrorDescription($code, $replacements): string
	{
		return Loc::getMessage("ERROR_FILE_{$code}_DESC", $replacements) ?: '';
	}
}
