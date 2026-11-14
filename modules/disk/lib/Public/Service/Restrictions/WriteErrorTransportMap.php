<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Service\Restrictions;

use Bitrix\Disk\File;

/**
 * Registry of "domain refusal of a write -> how a transport shows it", owned by the module.
 *
 * The registry is read from outside the module - the WebDAV transport of dav asks it for the
 * status of a refused write - so it lives in the public part of disk and changes only together
 * with the cross feature contract it belongs to.
 *
 * A transport never spells the representation out on its own: it asks this map by the code of the
 * domain error it holds and shows what it gets back. A code the map knows nothing about has no
 * representation anywhere, and the transport answers exactly as it did before the refusal existed.
 *
 * The codes themselves are owned by the models the writes are refused for, and the rows are named
 * by them: this class holds representations, never a code of its own.
 *
 * The table is where features meet: another restriction of the write path adds a row of its own,
 * and every transport it says nothing about keeps its former answer through the null.
 *
 * A row also tells whether the refusal is final. A channel that repeats a write of its own accord -
 * a webhook the platform re-delivers until it is answered - has to know whether the second attempt
 * can end differently; the answer is one, and it is kept here rather than spelled out by each
 * channel. A code the map says nothing about is no final refusal: an unknown obstacle may be gone
 * by the next attempt, so the write is left retryable, as it was before any refusal existed.
 */
class WriteErrorTransportMap
{
	public const DESKTOP_STATUS_FORMAT_RESTRICTED = 'format_restricted';

	/**
	 * The desktop protocol already names a lack of room, and a refusal by a quota of the disk is a
	 * lack of room to the client: it joins that status instead of adding one the clients in the
	 * field would not know.
	 */
	public const DESKTOP_STATUS_NO_SPACE = 'no_space';

	public const UPLOADER_ERROR_FILE_TYPE_NOT_ALLOWED = 'FILE_TYPE_NOT_ALLOWED';
	public const UPLOADER_ERROR_PERSONAL_QUOTA_EXCEEDED = 'PERSONAL_QUOTA_EXCEEDED';

	protected const WEBDAV_HTTP_STATUS = 'webDavHttpStatus';
	protected const DESKTOP_STATUS = 'desktopStatus';
	protected const REST_ERROR_CODE = 'restErrorCode';
	protected const UPLOADER_ERROR_CODE = 'uploaderErrorCode';
	protected const RETRY_IS_POINTLESS = 'retryIsPointless';

	protected const REPRESENTATIONS = [
		File::ERROR_FORMAT_RESTRICTED => [
			self::WEBDAV_HTTP_STATUS => 403,
			self::DESKTOP_STATUS => self::DESKTOP_STATUS_FORMAT_RESTRICTED,
			self::REST_ERROR_CODE => File::ERROR_FORMAT_RESTRICTED,
			self::UPLOADER_ERROR_CODE => self::UPLOADER_ERROR_FILE_TYPE_NOT_ALLOWED,
			self::RETRY_IS_POINTLESS => true,
		],
		File::ERROR_PERSONAL_QUOTA_EXCEEDED => [
			self::WEBDAV_HTTP_STATUS => 507,
			self::DESKTOP_STATUS => self::DESKTOP_STATUS_NO_SPACE,
			self::REST_ERROR_CODE => File::ERROR_PERSONAL_QUOTA_EXCEEDED,
			self::UPLOADER_ERROR_CODE => self::UPLOADER_ERROR_PERSONAL_QUOTA_EXCEEDED,
			// The same content is stored once room is freed: a channel that repeats the write must repeat it.
			self::RETRY_IS_POINTLESS => false,
		],
		File::ERROR_FORMAT_NOT_VERIFIED => [
			self::WEBDAV_HTTP_STATUS => 403,
			self::REST_ERROR_CODE => File::ERROR_FORMAT_NOT_VERIFIED,
			// The content is checked once the read budget is taken anew: a channel repeating the write must repeat it.
			self::RETRY_IS_POINTLESS => false,
		],
	];

	/**
	 * HTTP status a WebDAV write answers the refusal with.
	 *
	 * @param string $domainCode Code of the domain error.
	 * @return int|null
	 */
	public static function getWebDavHttpStatus(string $domainCode): ?int
	{
		return static::REPRESENTATIONS[$domainCode][static::WEBDAV_HTTP_STATUS] ?? null;
	}

	/**
	 * Status of the desktop protocol the refusal is named by. A client that knows no such status
	 * reads the answer as a refusal of the general kind, so the old contract stays whole.
	 *
	 * @param string $domainCode Code of the domain error.
	 * @return string|null
	 */
	public static function getDesktopStatus(string $domainCode): ?string
	{
		return static::REPRESENTATIONS[$domainCode][static::DESKTOP_STATUS] ?? null;
	}

	/**
	 * Error code a REST client is refused by. REST names the refusal by the domain code itself.
	 *
	 * @param string $domainCode Code of the domain error.
	 * @return string|null
	 */
	public static function getRestErrorCode(string $domainCode): ?string
	{
		return static::REPRESENTATIONS[$domainCode][static::REST_ERROR_CODE] ?? null;
	}

	/**
	 * Error code of the uploader the refusal of a single file is carried by. The text stays the
	 * one of the domain error: the code tells the uploader what happened, the text tells the user.
	 *
	 * @param string $domainCode Code of the domain error.
	 * @return string|null
	 */
	public static function getUploaderErrorCode(string $domainCode): ?string
	{
		return static::REPRESENTATIONS[$domainCode][static::UPLOADER_ERROR_CODE] ?? null;
	}

	/**
	 * Whether a write refused under this code would be refused again for the very same content.
	 * Only a refusal by the content itself is final; everything the map does not name may be an
	 * obstacle of the moment, and a channel that can repeat the write must repeat it.
	 *
	 * @param string $domainCode Code of the domain error.
	 * @return bool
	 */
	public static function isRetryPointless(string $domainCode): bool
	{
		return (static::REPRESENTATIONS[$domainCode][static::RETRY_IS_POINTLESS] ?? false) === true;
	}

	/**
	 * Code of the final refusal a write left behind, or null when the write left none.
	 *
	 * A model may leave more than one error behind, and a channel that repeats a write has to know
	 * whether any of them ends the matter. The walk over the collection is the same for every
	 * channel, so it is written once here rather than in each of them: OnlyOffice, the webhook of
	 * Vibeoffice and the board service all read the errors of a refused write through this.
	 *
	 * @param iterable $errors Errors the model was left with, {@see \Bitrix\Disk\File::getErrors()}.
	 * @return string|null
	 */
	public static function findFinalRefusalCode(iterable $errors): ?string
	{
		foreach ($errors as $error)
		{
			$code = (string)$error->getCode();
			if (static::isRetryPointless($code))
			{
				return $code;
			}
		}

		return null;
	}

	/**
	 * Code of any refusal this registry knows how to show, or null when the write left none.
	 *
	 * Some channels need to decide whether a domain refusal already carries user-facing words. That
	 * is not the same question as finality: the personal quota is retryable once room is freed, but
	 * the user still has to see the quota refusal instead of a general write failure.
	 *
	 * @param iterable $errors Errors the model was left with, {@see \Bitrix\Disk\File::getErrors()}.
	 * @return string|null
	 */
	public static function findMappedRefusalCode(iterable $errors): ?string
	{
		foreach ($errors as $error)
		{
			$code = (string)$error->getCode();
			if (isset(static::REPRESENTATIONS[$code]))
			{
				return $code;
			}
		}

		return null;
	}
}
