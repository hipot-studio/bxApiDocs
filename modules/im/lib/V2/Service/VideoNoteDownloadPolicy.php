<?php

namespace Bitrix\Im\V2\Service;

use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserType;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class VideoNoteDownloadPolicy
{
	private const OPTION_MODULE = 'im';
	private const OPTION_NAME = 'video_note_download_available';
	private const OPTION_SITE_GLOBAL = '';

	/**
	 * The permission is granted, not taken away: it belongs to the people of the portal and to its own bots.
	 * A type of user added later - one more external auth, one more flavour of an outside participant -
	 * stays denied until it is named here, instead of slipping through a list of the denied ones.
	 */
	private const ALLOWED_USER_TYPES = [UserType::USER, UserType::BOT];

	/** A bot acts on behalf of the portal itself, so its auth id does not make it an outsider. */
	private const AUTH_TYPES_KEPT_INTERNAL = ['bot'];

	/**
	 * The default is not stored in default_option.php and is never written to the DB on read:
	 * a hardcoded fallback would override the cloud value for good.
	 */
	public function isPortalEnabled(): bool
	{
		$saved = Option::getRealValue(self::OPTION_MODULE, self::OPTION_NAME, self::OPTION_SITE_GLOBAL);

		if ($saved === null)
		{
			return IsModuleInstalled('bitrix24');
		}

		return $saved === 'Y';
	}

	public function isAllowed(User $user): bool
	{
		return $this->isPortalEnabled() && $this->isAllowedUser($user);
	}

	/**
	 * Answer for a point that holds nothing but an id of a disk file: the legacy ajax branch that saves a
	 * file to the own Disk and the legacy REST method behind the same action. Recognising a video note lives
	 * here once, so both refuse exactly the files the V2 points refuse.
	 *
	 * The read permission is weighed ahead of the recognition, the same order the V2 points keep: a file the
	 * user cannot read is not a refusal of the policy but the plain error of the caller, so a stranger
	 * guessing ids cannot tell a missing file from a video note of a chat they have no access to.
	 */
	public function isDiskFileAllowed(int $diskFileId, User $user): bool
	{
		if (!Loader::includeModule('disk'))
		{
			return true;
		}

		$file = FileItem::initByDiskFileId($diskFileId);

		if ($file === null || !$this->canRead($file, $user))
		{
			return true;
		}

		if (!$file->isActualVideoNote())
		{
			return true;
		}

		return $this->isAllowed($user);
	}

	/**
	 * The predicate of the V2 points (@see \Bitrix\Im\V2\Controller\Filter\CheckDiskFileAccess), asked
	 * for the user handed to the policy and not for the one of the current context: the legacy points call
	 * this on behalf of their own actor.
	 */
	private function canRead(FileItem $file, User $user): bool
	{
		$diskFile = $file->getDiskFile();
		$storage = $diskFile?->getStorage();

		return isset($diskFile, $storage)
			&& $diskFile->canRead($storage->getSecurityContext((int)$user->getId()))
		;
	}

	/**
	 * The type of the user alone would not do: UserExternal keeps UserType::USER, and only the id of the
	 * auth tells an external account apart from an employee.
	 */
	private function isAllowedUser(User $user): bool
	{
		return $user->getId() !== null
			&& in_array($user->getType(), self::ALLOWED_USER_TYPES, true)
			&& $user->isInternalType(self::AUTH_TYPES_KEPT_INTERNAL)
		;
	}
}
