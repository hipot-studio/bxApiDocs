<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\Quota\QuotaEditingRestriction;
use Bitrix\Disk\Internal\Service\Restrictions\EditingRestrictionResolver;
use Bitrix\Disk\Internal\Service\Restrictions\FormatEditingRestriction;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;

/**
 * Whether the viewer of the Markdown page may enter editing now, and why not; also the one dictionary of the
 * texts of the refusals of editing, for the page and for the actions alike.
 *
 * The order of the questions: the policy, the right, the format policy and the personal quota, the lock of the
 * object (while the portal uses such locks), the size, the encoding, and last the md sessions of the file.
 */
final class ReadinessService
{
	public const ERROR_TOO_LARGE = 'too_large';
	public const ERROR_ENCODING_UNSUPPORTED = 'encoding_unsupported';
	public const ERROR_FILE_LOCKED = 'file_locked';
	public const ERROR_FORMAT_RESTRICTED = 'format_restricted';
	public const ERROR_PERSONAL_QUOTA_EXCEEDED = 'personal_quota_exceeded';

	private const RESTRICTION_CODES = [
		File::ERROR_FORMAT_RESTRICTED => self::ERROR_FORMAT_RESTRICTED,
		File::ERROR_PERSONAL_QUOTA_EXCEEDED => self::ERROR_PERSONAL_QUOTA_EXCEEDED,
	];

	private readonly SessionService $sessions;
	private readonly AccessResolver $accessResolver;
	private readonly Utf8Inspector $utf8Inspector;

	public function __construct(
		?SessionService $sessions = null,
		?AccessResolver $accessResolver = null,
		?Utf8Inspector $utf8Inspector = null,
	)
	{
		$this->sessions = $sessions ?? new SessionService();
		$this->accessResolver = $accessResolver ?? new AccessResolver();
		$this->utf8Inspector = $utf8Inspector ?? new Utf8Inspector();
	}

	public function assess(
		File $file,
		int $viewerId,
		?string $uniqueCode = null,
		?ExternalLink $link = null,
	): Readiness
	{
		$access = $this->accessResolver->resolve($file, $viewerId, $uniqueCode, $link);
		if (!$access->isSuccess())
		{
			return new Readiness(hasRight: false, canEdit: false);
		}

		/** @var EditorIdentity $identity */
		$identity = $access->getData()['identity'];
		$refusal = $this->findRefusal($identity->file, $viewerId, $identity->isGuest());

		return new Readiness(hasRight: true, canEdit: $refusal === null, refusal: $refusal);
	}

	/**
	 * @param DocumentSession|null $blocking The session a "session_busy" refusal is about.
	 * @param bool $viewerIsGuest The viewer came by a public link: the one who edits is never named to them.
	 */
	public function describeRefusal(
		string $code,
		?DocumentSession $blocking,
		int $viewerId,
		bool $viewerIsGuest = false,
	): Error
	{
		return match ($code)
		{
			SessionService::ERROR_SESSION_BUSY => $this->describeBusy($blocking, $viewerId, $viewerIsGuest),
			self::ERROR_TOO_LARGE => $this->makeError($code, 'DISK_MARKDOWN_EDITOR_REFUSAL_TOO_LARGE'),
			self::ERROR_ENCODING_UNSUPPORTED => $this->makeError($code, 'DISK_MARKDOWN_EDITOR_REFUSAL_ENCODING'),
			self::ERROR_FILE_LOCKED => $this->makeError($code, 'DISK_MARKDOWN_EDITOR_REFUSAL_FILE_LOCKED'),
			SessionService::ERROR_CONTENT_CHANGED => $this->makeError(
				$code,
				'DISK_MARKDOWN_EDITOR_ERROR_CONTENT_CHANGED',
			),
			SessionService::ERROR_SESSION_EXPIRED => $this->makeError(
				$code,
				'DISK_MARKDOWN_EDITOR_ERROR_SESSION_EXPIRED',
			),
			AccessResolver::ERROR_ACCESS_DENIED => $this->makeError($code, 'DISK_MARKDOWN_EDITOR_ERROR_ACCESS_DENIED'),
			self::ERROR_FORMAT_RESTRICTED => new Error((new FormatEditingRestriction())->getHint(), $code),
			self::ERROR_PERSONAL_QUOTA_EXCEEDED => new Error((new QuotaEditingRestriction())->getHint(), $code),
			default => $this->makeError($code, 'DISK_MARKDOWN_EDITOR_ERROR_FEATURE_DISABLED'),
		};
	}

	private function findRefusal(File $file, int $viewerId, bool $viewerIsGuest): ?Error
	{
		$restriction = (new EditingRestrictionResolver())->findRefusal(
			$file->getName(),
			$file,
			$viewerId > 0 ? $viewerId : null,
		);
		if ($restriction !== null)
		{
			return new Error(
				$restriction->getMessage(),
				self::RESTRICTION_CODES[$restriction->getCode()] ?? $restriction->getCode(),
				$restriction->getCustomData(),
			);
		}

		$lock = $this->isObjectLockEnabled() ? $file->getLock() : null;
		if ($lock !== null && $lock->isExclusive() && !$lock->canUnlock($viewerId))
		{
			return $this->describeRefusal(self::ERROR_FILE_LOCKED, null, $viewerId);
		}

		if ((int)$file->getSize() > Configuration::getMaxSizeForMarkdownRender())
		{
			return $this->describeRefusal(self::ERROR_TOO_LARGE, null, $viewerId);
		}

		if (!$this->utf8Inspector->isUtf8($file))
		{
			return $this->describeRefusal(self::ERROR_ENCODING_UNSUPPORTED, null, $viewerId);
		}

		$blocking = $this->sessions->findBlockingSession((int)$file->getId());
		if ($blocking !== null)
		{
			return $this->describeRefusal(SessionService::ERROR_SESSION_BUSY, $blocking, $viewerId, $viewerIsGuest);
		}

		return null;
	}

	/**
	 * {@see Configuration::isEnabledObjectLock()} keeps its first answer for the whole process.
	 */
	private function isObjectLockEnabled(): bool
	{
		return Option::get(Driver::INTERNAL_MODULE_ID, 'disk_object_lock_enabled', 'N') === 'Y';
	}

	/**
	 * The custom data tell, besides whose the session is, in how many seconds it goes out by itself should its
	 * tab stop pulsing (`expiresIn`, null when unknown): the page waiting for the file asks again then.
	 */
	private function describeBusy(?DocumentSession $blocking, int $viewerId, bool $viewerIsGuest): Error
	{
		$ownerId = $blocking?->getUserId();
		$expiresIn = $blocking === null ? null : $this->sessions->secondsUntilExpiry($blocking);
		if ($ownerId !== null && $viewerId > 0 && $ownerId === $viewerId)
		{
			return $this->makeError(
				SessionService::ERROR_SESSION_BUSY,
				'DISK_MARKDOWN_EDITOR_REFUSAL_OWN_SESSION',
				customData: ['own' => true, 'ownerId' => $ownerId, 'expiresIn' => $expiresIn],
			);
		}

		// Neither the name nor the id of the one who edits goes to a guest of a public link.
		$owner = $ownerId > 0 && !$viewerIsGuest ? $this->loadUser($ownerId) : null;
		if ($owner === null || $owner['EXTERNAL_AUTH_ID'] === DocumentEditorUser::EXTERNAL_AUTH_ID)
		{
			return $this->makeError(
				SessionService::ERROR_SESSION_BUSY,
				'DISK_MARKDOWN_EDITOR_REFUSAL_BUSY_BY_GUEST',
				customData: ['own' => false, 'ownerId' => null, 'expiresIn' => $expiresIn],
			);
		}

		return $this->makeError(
			SessionService::ERROR_SESSION_BUSY,
			'DISK_MARKDOWN_EDITOR_REFUSAL_BUSY_BY_USER',
			['#USER_NAME#' => \CUser::FormatName(\CSite::GetNameFormat(false), $owner, true, false)],
			['own' => false, 'ownerId' => $ownerId, 'expiresIn' => $expiresIn],
		);
	}

	private function loadUser(int $userId): ?array
	{
		$user = UserTable::getRow([
			'select' => ['ID', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EXTERNAL_AUTH_ID'],
			'filter' => ['=ID' => $userId],
		]);

		return is_array($user) ? $user : null;
	}

	private function makeError(string $code, string $messageId, array $replace = [], ?array $customData = null): Error
	{
		return new Error((string)Loc::getMessage($messageId, $replace), $code, $customData);
	}
}
