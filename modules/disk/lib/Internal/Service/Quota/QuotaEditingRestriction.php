<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Quota;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\Storage;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * Tells whether a document may still be opened for editing under the quota of the personal disk it
 * lies in.
 *
 * Editing ends in a write back to the same file, so the question is the write one - only asked
 * before the editor opens. It is asked of the smallest write there is, which is refused exactly
 * while the disk holds no room for one more byte - filled to the assigned quota or past it: the size
 * of the coming edit is known to nobody, so the state of the disk is what the opening is judged by,
 * over the last known used size (ALG-01). A recalculation in flight closes nothing on its own.
 *
 * Everything the quota says nothing about is left alone: a group storage, the common one, a portal
 * the policy is not applied on, an employee with no quota row - all of them open the editor as
 * before. Viewing and downloading are never touched.
 *
 * The words the refusal carries depend on who is reading them: the employee the disk belongs to is
 * told what to delete, and everyone else the document reaches is told who to ask instead.
 *
 * A session already open is not judged here at all. An employee whose disk runs out while they work
 * keeps saving to the end of the session (AC-048) and is left to viewing at the next opening.
 */
class QuotaEditingRestriction
{
	/**
	 * The smallest write a session could end in. A disk that cannot take even this one is out of
	 * room, and a session opened over it would save by a context the quota lets through anyway.
	 */
	private const SMALLEST_WRITE = 1;

	/**
	 * @param PersonalQuotaService|null $quotaService Quota service; the container answers when it is omitted.
	 */
	public function __construct(
		private ?PersonalQuotaService $quotaService = null,
	)
	{
	}

	/**
	 * Why the way into the editor is closed for a document lying in this storage, or null while it
	 * is open. The refusal carries the words shown over the inactive editing control, the domain
	 * code of the quota, and the sizes the state was measured by.
	 *
	 * @param Storage|null $storage Storage the document being opened lies in.
	 * @return Error|null
	 */
	public function findRefusal(?Storage $storage): ?Error
	{
		$refusalData = $this->findRefusalData($storage);

		return $refusalData === null
			? null
			: new Error($this->findHint($storage), File::ERROR_PERSONAL_QUOTA_EXCEEDED, $refusalData)
		;
	}

	/**
	 * The text the interface shows over the inactive editing control of the employee the disk
	 * belongs to. It names what only they can do about it - their files, their old versions, their
	 * recycle bin.
	 *
	 * @return string
	 */
	public function getHint(): string
	{
		return (string)Loc::getMessage('DISK_QUOTA_EDITING_RESTRICTION_HINT');
	}

	/**
	 * The same text for everyone else the document reaches - a colleague it was shared with, a
	 * reader of a link. They are told why the editing is closed and who can open it, and nothing
	 * about the state of a disk that is not theirs.
	 *
	 * @return string
	 */
	public function getForeignHint(): string
	{
		return (string)Loc::getMessage('DISK_QUOTA_EDITING_RESTRICTION_HINT_FOREIGN');
	}

	/**
	 * Which of the two texts this reader is shown. A refusal at all means the storage is a personal
	 * one, so its entity is the employee the quota belongs to.
	 *
	 * A reader that could not be named is a stranger here: the state of a disk of somebody else is
	 * not told to a request nobody stands behind.
	 */
	private function findHint(?Storage $storage): string
	{
		$ownerId = (int)$storage?->getEntityId();

		return $ownerId > 0 && $ownerId === (int)CurrentUser::get()->getId()
			? $this->getHint()
			: $this->getForeignHint()
		;
	}

	/**
	 * The sizes the state of the disk was measured by, or null while the employee has room left.
	 *
	 * @return array<string, int>|null
	 */
	private function findRefusalData(?Storage $storage): ?array
	{
		$result = $this->getQuotaService()->canWriteToStorage(
			$storage,
			self::SMALLEST_WRITE,
			WriteOperationContext::UserUpload,
		);
		if ($result->isSuccess())
		{
			return null;
		}

		return ($result->getErrors()[0] ?? null)?->getCustomData() ?? [];
	}

	/**
	 * The service is resolved on the first question asked about a storage: the paths that only show
	 * the hint reach no quota at all.
	 */
	protected function getQuotaService(): PersonalQuotaService
	{
		return $this->quotaService ??= ServiceLocator::getInstance()->get(PersonalQuotaService::class);
	}
}
