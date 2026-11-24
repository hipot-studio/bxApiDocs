<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\DockedUploader;

use Bitrix\Disk;
use Bitrix\Disk\Config\Feature\DockedUploaderUnlimitedFileSizeFlag;
use Bitrix\Disk\Internal\Repository\Interface\DockedUploadQueueRepositoryInterface;
use Bitrix\Disk\Internal\Service\DockedUpload\FolderResolverService;
use Bitrix\Disk\Internal\Service\DockedUpload\QuotaScenario;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\UI\FileUploader\CanUploadResult;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\Contracts\CustomFingerprint;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;
use Bitrix\UI\FileUploader\UploaderError;
use Bitrix\UI\FileUploader\UploadRequest;
use Bitrix\UI\FileUploader\UploadResult;
use Psr\Log\LoggerInterface;

Loader::requireModule('ui');

/**
 * ui.uploader consumer controller of the docked upload transport (TRANSPORT-01).
 *
 * The docked path fixes files with DEFERRED commit: bytes travel through this
 * controller, the element stays a temp token, and a separate updateItem request
 * drives {@see \Bitrix\Disk\Internal\Service\DockedUpload\FileCommitService}
 * (ALG-01). That is why the user-field controller
 * ({@see \Bitrix\Disk\Uf\Integration\DiskUploaderController}) cannot be reused
 * here - it is a structural reference only. Four of its traits break the docked
 * path, so this class deliberately does the opposite of each:
 *
 *  1. onUploadComplete is NOT overridden. The empty base implementation is what
 *     the docked path needs: committing here (addFile + makePersistent) would
 *     both create the object without an updateItem and drop the temp row, after
 *     which getPendingFiles (GUID + UPLOADED = true) cannot resolve the token.
 *  2. CustomRemove is NOT implemented, so Uploader::remove() keeps running its
 *     regular temp branch (getGuidFromToken -> TempFileTable -> delete). That
 *     branch is the one the docked cancel path and the TTL agent rely on.
 *  3. CustomFingerprint returns a docked-scope constant. The salt must not
 *     depend on the session or the user: the TTL agent frees a foreign temp from
 *     cron, holding neither. Ownership is re-validated against the queue instead
 *     (FileCommitService::resolveTempOwner, Q-5).
 *  4. options are pinned to an empty array. controllerOptions arrive from the
 *     client and are part of the token salt, so anything forwarded would let a
 *     client steer the salt. folderId has no place there either: DI builds this
 *     controller lazily, without a folder, and the docked target folder lives in
 *     the queue row (TARGET_FOLDER_ID).
 *
 * Renaming the class or changing {@see self::FINGERPRINT} re-salts every live
 * token and is therefore a BREAKING change: uncommitted elements fail and their
 * temp files are left to the daily TempFileAgent GC.
 *
 * The class sits in a namespace mapped onto the engine api, but it never becomes
 * an ajax surface: ControllerBuilder only picks up Bitrix\Main\Engine\Controller
 * subclasses, and this is an UploaderController.
 */
final class Transport extends UploaderController implements CustomFingerprint
{
	/**
	 * Salt component replacing the session fingerprint. Constant on purpose: see
	 * the class doc - a token signed in a web request must resolve from cron.
	 */
	private const FINGERPRINT = 'disk.dockedUploader.transport.v1';

	/** Per-file ceiling of the docked transport (Q-6); single-sourced with the create pre-check. */
	public const MAX_FILE_SIZE = Disk\Configuration::DOCKED_UPLOAD_MAX_FILE_SIZE;

	private readonly QuotaScenario $quotaScenario;
	private readonly LoggerInterface $logger;

	public function __construct(
		array $options,
		?QuotaScenario $quotaScenario = null,
		?LoggerInterface $logger = null,
	)
	{
		$this->quotaScenario = $quotaScenario ?? new QuotaScenario();
		$this->logger = $logger ?? LoggerFactory::create(feature: 'docked upload transport');

		// TRANSPORT-01: the argument is ignored by contract. It reaches us from the
		// client through ControllerResolver::createController(), and getTokenSalt()
		// hashes the options in, so honouring it would hand the client control over
		// the salt.
		parent::__construct([]);
	}

	public function isAvailable(): bool
	{
		return
			$GLOBALS['USER']->isAuthorized()
			&& Loader::includeModule('disk')
			&& Disk\Configuration::isEnabledDockedUploader()
		;
	}

	public function getFingerprint(): string
	{
		return self::FINGERPRINT;
	}

	public function getConfiguration(): Configuration
	{
		$configuration = new Configuration();
		$configuration->setMaxFileSize(
			Feature::isEnabled(DockedUploaderUnlimitedFileSizeFlag::class) ? null : self::MAX_FILE_SIZE,
		);
		// Docked uploads carry arbitrary Disk files, not pictures: a large image
		// must degrade to a plain file instead of failing as an oversize image, and
		// an unknown image type must not reject the upload.
		$configuration->setTreatOversizeImageAsFile(true);
		$configuration->setIgnoreUnknownImageTypes(true);

		return $configuration;
	}

	/**
	 * Coarse transport gate - deliberately NOT a folder permission check.
	 *
	 * This controller does not know the target folder by construction (options are
	 * empty), and the ADR requires permissions to be re-verified per item at commit
	 * time, which FolderResolverService does inside FileCommitService.
	 *
	 * What it does narrow is who may spend temp space at all: the user must have an
	 * unfinished queue. Without it any authorized account could park files
	 * in temp indefinitely, never committing a single one.
	 * Curing that at the root means redesigning ui.fileuploader for all of its
	 * consumers, which the ADR keeps outside this module - so the surface is narrowed
	 * here instead, from "any authorized user" to "a user with a live queue".
	 *
	 * It costs a legitimate upload nothing: the client always has the queue answered
	 * by API-01 before it moves a byte, and a restore brings the queue back along with
	 * the rows to re-feed. A queue expired out of the restorable window is closed by
	 * definition, and re-picking those files creates a new one. Cheap enough to sit on
	 * this path: one row over IX_DISK_DOCKED_UPLOAD_QUEUE_USER_ID_STATUS, asked once
	 * per file - the framework calls canUpload on the first chunk, not on every one.
	 *
	 * Called as call_user_func([$controller, 'canUpload'], $uploadRequest), while
	 * the base signature takes no parameter - hence the optional argument.
	 */
	public function canUpload(?UploadRequest $uploadRequest = null): CanUploadResult|bool
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0 || !Disk\Configuration::isEnabledDockedUploader())
		{
			return false;
		}

		if ($this->getFolderResolver()->isAccessRestricted($userId))
		{
			return false;
		}

		return $this->getQueueRepository()->hasActiveByUser($userId);
	}

	/**
	 * Belt 2 of the quota detection (mechanism 1: the portal-wide main/disk_space).
	 *
	 * Belt 1 lives in FileCommitService::commit(), i.e. on a path execution never
	 * reaches once the portal quota is out: the limit is enforced by CFile inside
	 * the ui temp-save, BEFORE any Disk commit. Both routes into it surface here -
	 * checkFile() on the first chunk (CHECK_FILE_FAILED, no token yet) and
	 * TempFile::commit() on the last one (SAVE_FILE_FAILED, cause already lost) -
	 * and onUploadError is the only docked hook that sees either.
	 *
	 * Classification is a deterministic re-ask, never a match on the code or the
	 * localized message: CHECK_FILE_FAILED covers every CFile::checkFile rejection
	 * and SAVE_FILE_FAILED every write failure, so neither identifies a quota.
	 *
	 * The queue rows are deliberately untouched: this controller does not know the
	 * item (options are empty) and the token is not in TEMP_TOKEN yet - updateItem
	 * writes it only after a successful transfer. The durable stop is applied when
	 * the client reports the failure back through updateItem.transportError, which
	 * the server re-verifies on its own.
	 */
	public function onUploadError(UploadResult $uploadResult): void
	{
		// Available on the commit stage even when the temp row is already DELETED;
		// absent on a first-chunk failure, where the size is simply unknown.
		$size = (int)($uploadResult->getTempFile()?->getSize() ?? 0);

		$stop = $this->quotaScenario->detectPortalQuotaStop($size);
		if ($stop === null)
		{
			return;
		}

		// Hand the client a machine-readable server verdict (code + customData) so it
		// renders the quota UX without computing free space itself.
		$uploadResult->addError(new UploaderError(
			$stop->getErrorCode(),
			'Disk quota exceeded',
			[
				'scenario' => $stop->getScenario(),
				'infoHelperCode' => $stop->getInfoHelperCode(),
				'tariffButtonAvailable' => $stop->isTariffButtonAvailable(),
			],
		));

		// AC-060: log the quota stop at the transition point, mirroring
		// FileCommitService::applyQuotaStop. Metadata only - no PII, no content.
		$this->logger->info('docked transport quota stop', [
			'scenario' => $stop->getScenario(),
			'errorCode' => $stop->getErrorCode(),
			'size' => $size,
		]);
	}

	/**
	 * The docked path never routes b_file ids through the Uploader - only temp
	 * tokens - and canView()/verifyFileOwner() are consulted exclusively by the
	 * b_file branches of load()/remove(), which temp tokens never enter.
	 */
	public function canView(): bool
	{
		return false;
	}

	/** Does not block freeing temp: remove() only consults it for b_file ids. */
	public function canRemove(): bool
	{
		return false;
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
	}

	private function getFolderResolver(): FolderResolverService
	{
		return ServiceLocator::getInstance()->get('disk.dockedUpload.folderResolver');
	}

	private function getQueueRepository(): DockedUploadQueueRepositoryInterface
	{
		return ServiceLocator::getInstance()->get(DockedUploadQueueRepositoryInterface::class);
	}
}
