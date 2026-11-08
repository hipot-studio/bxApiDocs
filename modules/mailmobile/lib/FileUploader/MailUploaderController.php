<?php

namespace Bitrix\MailMobile\FileUploader;

use Bitrix\MailMobile\Internal\Integration\Disk\AttachmentPlacement;
use Bitrix\MailMobile\Internal\Integration\Disk\AttachmentPlacementFactory;
use Bitrix\MailMobile\Internal\Integration\Mail\AttachmentLimit;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\UploadRequest;
use Bitrix\UI\FileUploader\UploadResult;

final class MailUploaderController extends UploaderController
{
	public function __construct()
	{
		// The options are part of the signature of every upload token, and the draft channel of the mail
		// module rebuilds this controller by name to validate one. Nothing may be added here.
		parent::__construct([
			'type' => 'mailUploadedFiles',
			'userId' => (int)CurrentUser::get()?->getId(),
		]);
	}

	public function isAvailable(): bool
	{
		if (!Loader::includeModule('mail'))
		{
			return false;
		}

		return true;
	}

	/**
	 * @throws LoaderException
	 */
	public function getConfiguration(): Configuration
	{
		$configuration = new Configuration();

		$placement = $this->getPlacementForNewUpload();
		if ($placement !== null)
		{
			// A single upload is capped by what the placement accepts, not by the attachment limit of a
			// message: the scenario exists to carry a file a message cannot, and the sum of the attachments
			// is answered by the send. The cap stays finite, so an unbounded upload is still refused.
			$configuration->setMaxFileSize($placement->getUploadSizeLimit());
			$configuration->setTreatOversizeImageAsFile(true);
			$configuration->setIgnoreUnknownImageTypes(true);

			return $configuration;
		}

		if (Loader::includeModule('mail'))
		{
			$maxSize = AttachmentLimit::getMaxTotalSizeAfterEncoding();
			if ($maxSize > 0)
			{
				$configuration->setMaxFileSize($maxSize);
			}
		}

		return $configuration;
	}

	public function canUpload(?UploadRequest $uploadRequest = null): bool
	{
		$placement = $this->getPlacementForNewUpload();
		if ($placement === null)
		{
			return true;
		}

		return $placement->canAdd((int)($uploadRequest?->getSize() ?? 0));
	}

	/**
	 * The uploaded file becomes an object of the Disk, so what leaves for the client is the identifier of
	 * that object. Everything the scenario of the large attachment does further on speaks in those alone.
	 */
	public function onUploadComplete(UploadResult $uploadResult): void
	{
		$this->getPlacementForNewUpload()?->place($uploadResult);
	}

	public function canView(): bool
	{
		return true;
	}

	/**
	 * Access to a stored file is decided by the permission model of the Disk, not by this receiver. This is
	 * a question about an object that is already there, so it is asked of a placement of its own and not of
	 * the one a new upload goes through: an unverified ownership is a refusal, because a file of the
	 * collection starts out as "not own" (Bitrix\UI\FileUploader\FileOwnership).
	 */
	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		$this->getPlacementOfStoredObjects()?->verifyFileOwner($files);
	}

	public function canRemove(): bool
	{
		return false;
	}

	/**
	 * The placement a new upload is stored through, or null while the scenario is not on for this portal.
	 * With no placement the receiver behaves exactly as it did before the move, which is the way back from
	 * it: the former limit of a message, no permission of the Disk asked, nothing stored outside the upload
	 * session. The whole gate of the scenario decides, not the rollout flag of mobile mail alone.
	 */
	private function getPlacementForNewUpload(): ?AttachmentPlacement
	{
		return AttachmentPlacementFactory::createForNewAttachments();
	}

	/**
	 * The placement an object already stored in the Disk is read through. The gate of the scenario is not
	 * asked here: whether a file that exists may be read is a question for the permission model of the Disk.
	 */
	private function getPlacementOfStoredObjects(): ?AttachmentPlacement
	{
		return AttachmentPlacementFactory::createForExistingObjects();
	}
}
