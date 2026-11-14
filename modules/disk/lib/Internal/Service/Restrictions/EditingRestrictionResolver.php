<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\Quota\QuotaEditingRestriction;
use Bitrix\Disk\Storage;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectException;

/**
 * The one place the points deciding whether a document opens for editing ask about it.
 *
 * There are two reasons the way into the editor closes - the format the portal restricts and the
 * personal quota the employee has run over - and a point that asked each of them on its own would
 * answer the interface with a bare "no". The verdict carries its reason instead: the text standing
 * over the inactive editing control differs between the two, and so does the code the transports
 * without an interface of the product read the refusal by.
 *
 * The formats are asked first and the quota only where they allowed, which is the order the cost
 * asks for: a format is answered by the name of the document and reads nothing at all, while the
 * quota reads the counter row of the owner of the disk the editing would be written into. That disk
 * is looked up no earlier than that, so a document the format already refused costs no look into it.
 *
 * Nothing here judges a session already open - it is about the way in, not about saving.
 */
class EditingRestrictionResolver
{
	/**
	 * @param FormatEditingRestriction|null $formatRestriction Format part of the verdict.
	 * @param QuotaEditingRestriction|null $quotaRestriction Quota part of the verdict.
	 */
	public function __construct(
		private ?FormatEditingRestriction $formatRestriction = null,
		private ?QuotaEditingRestriction $quotaRestriction = null,
	)
	{
	}

	/**
	 * Why editing this document is closed, or null while it is open.
	 *
	 * The document is named and located separately on purpose: a link resolved to a version is
	 * judged by the name of that version, while the quota belongs to the disk the editing would be
	 * written into. A point knowing neither - a session addressed by its hash alone - is answered by
	 * the verdict its knowledge allows, and never by a refusal it could not measure.
	 *
	 * @param string|null $documentName Name the document is opened under.
	 * @param File|null $file File the document belongs to.
	 * @param int|null $userId User opening the document, whose format policy decides; the current
	 *        user when omitted.
	 * @return Error|null
	 */
	public function findRefusal(?string $documentName, ?File $file, ?int $userId = null): ?Error
	{
		$formatRestriction = $this->getFormatRestriction();
		if ($formatRestriction->isRestricted($documentName, $userId))
		{
			return new Error($formatRestriction->getHint(), File::ERROR_FORMAT_RESTRICTED);
		}

		return $this->getQuotaRestriction()->findRefusal($this->findStorageOfWrite($file));
	}

	/**
	 * The disk the editing of this document would be written into.
	 *
	 * A link is a document of its own only to the one looking at it: the version goes to the file it
	 * points at ({@see \Bitrix\Disk\FileLink::addVersion()}) while the link keeps lying in the disk
	 * of whoever it was shared with, so the quota asked is the one of the real file. A file that is
	 * no link answers with itself and costs nothing.
	 *
	 * A link whose target is gone, or is no file at all, is left to the write node: the way in stays
	 * open rather than closing over a disk that could not be found.
	 */
	private function findStorageOfWrite(?File $file): ?Storage
	{
		try
		{
			return $file?->getRealObject()?->getStorage();
		}
		catch (ObjectException)
		{
			return null;
		}
	}

	/**
	 * The same verdict for the points that show no reason of their own.
	 *
	 * @param string|null $documentName Name the document is opened under.
	 * @param File|null $file File the document belongs to.
	 * @param int|null $userId User opening the document; the current user when omitted.
	 * @return bool
	 */
	public function isRestricted(?string $documentName, ?File $file, ?int $userId = null): bool
	{
		return $this->findRefusal($documentName, $file, $userId) !== null;
	}

	protected function getFormatRestriction(): FormatEditingRestriction
	{
		return $this->formatRestriction ??= new FormatEditingRestriction();
	}

	protected function getQuotaRestriction(): QuotaEditingRestriction
	{
		return $this->quotaRestriction ??= new QuotaEditingRestriction();
	}
}
