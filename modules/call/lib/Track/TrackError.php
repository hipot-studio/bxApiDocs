<?php

namespace Bitrix\Call\Track;

/**
 * @internal
 */
class TrackError extends \Bitrix\Call\Error
{
	public const
		EMPTY_DOWNLOAD_URL = 'EMPTY_DOWNLOAD_URL',
		DOWNLOAD_ERROR = 'DOWNLOAD_ERROR',
		NETWORK_ERROR = 'NETWORK_ERROR',
		SAVE_FILE_ERROR = 'SAVE_FILE_ERROR',
		DISK_ATTACH_ERROR = 'DISK_ATTACH_ERROR',
		TRACK_NOT_FOUND_ERROR = 'TRACK_NOT_FOUND_ERROR',
		TRACK_DUPLICATE_ERROR = 'TRACK_DUPLICATE_ERROR',
		FILE_SIZE_ZERO = 'FILE_SIZE_ZERO',
		FILE_SIZE_MISMATCH = 'FILE_SIZE_MISMATCH',
		MIXER_DELETE_ERROR = 'MIXER_DELETE_ERROR',
		PREVIEW_ALREADY_EXISTS = 'preview_already_exists',
		STATIC_PREVIEW_NOT_FOUND = 'static_preview_not_found',
		FILE_ARRAY_ERROR = 'file_array_error',
		FINALIZE_FAILED = 'FINALIZE_FAILED',
		/**
		 * The address of a delivery answered, and not with a 200: the recording is not to be had
		 * there any more. Told of every delivery, the one that replaces a copy the portal holds
		 * and the one that lands on a row the portal has to make.
		 *
		 * Told apart from the code below and not merged into DOWNLOAD_ERROR, because the operator
		 * of the media service reads the code and has to act on it: this one is final - there is
		 * no second delivery of a recording the storage no longer serves - while the other is
		 * worth one more try. Neither weakens the check itself
		 * {@see \Bitrix\Call\Track\TrackService::checkDelivery()}.
		 */
		DELIVERY_FILE_GONE = 'DELIVERY_FILE_GONE',
		/**
		 * The address of a delivery could not be asked at all - no answer, or an answer of the
		 * storage that says nothing about the file (5xx). What is behind it is unknown, and an
		 * unknown is refused exactly like a miss: a copy thrown away for a replacement that never
		 * lands is a pack of tracks lost for good.
		 */
		DELIVERY_ADDRESS_UNCHECKED = 'DELIVERY_ADDRESS_UNCHECKED',
		/**
		 * A delivery meant to replace a row the portal holds names a kind of file the row is not.
		 * The row is addressed by the identifier the service gave the track, and the type says
		 * what the portal will do with the bytes behind it - show them in the chat, or hand them
		 * to the analysis - so a mismatch is a request about the wrong row, refused before the
		 * copy of the right one is let go of {@see \Bitrix\Call\Track\TrackService::takeDeliveredFile()}.
		 */
		DELIVERY_TYPE_MISMATCH = 'DELIVERY_TYPE_MISMATCH',
		TRACK_ERROR = 'TRACK_ERROR'
	;

	/**
	 * Media backend reports a recording failure without a cause code, and a bare code would reach
	 * analytics as the meaningless 'error_' status. A non-empty code stays usable as the real event
	 * label, minus the surrounding whitespace the blank check already treats as noise: keeping it would
	 * put 'error_ ERR_X ' into the analytics status and into the log line.
	 *
	 * @see \Bitrix\Call\Controller\CallController::trackErrorAction
	 */
	public static function normalize(string $code): string
	{
		$code = trim($code);

		return $code === '' ? self::TRACK_ERROR : $code;
	}
}
