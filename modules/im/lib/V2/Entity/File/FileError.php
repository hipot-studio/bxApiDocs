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
	/**
	 * Both are contract violations by the caller of im.v2.File.directDownload, not by the person behind it:
	 * a type outside the enum and a call made inside a REST batch. A correct client reaches neither, and
	 * both are left without a phrase for the same reason as UPDATE_STEP_OUT_OF_ORDER below.
	 */
	public const UNKNOWN_DOWNLOAD_TYPE = 'FILE_UNKNOWN_DOWNLOAD_TYPE';
	public const BATCH_NOT_ALLOWED = 'FILE_BATCH_NOT_ALLOWED';

	/**
	 * Internal guard against a step of the file scenario of an update called out of turn. Unreachable while
	 * UpdateService keeps the steps in order, and left without a phrase: a person can do nothing about it.
	 */
	public const UPDATE_STEP_OUT_OF_ORDER = 'FILE_UPDATE_STEP_OUT_OF_ORDER';
	public const FEATURE_DISABLED = 'FEATURE_DISABLED';

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
