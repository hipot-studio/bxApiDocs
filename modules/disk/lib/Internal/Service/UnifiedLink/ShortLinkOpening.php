<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\DownloadAccess;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessSource;
use Bitrix\Disk\Internal\Repository\Interface\ExternalLinkRepositoryInterface;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkOpenPolicy;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkPasswordService;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\User;
use Bitrix\Disk\Version;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

/**
 * The opening of a short public address /docs/pub/s/{code}/: the one manual link the code names, its file,
 * the exact revision the link was published for or none, and the canonical address the opening stays on.
 *
 * The code is looked up in the database of the current connection only, and every resolve checks the link
 * again: its type and scenario, whether it is alive and allowed by the portal policy, the file and the revision.
 * Nothing of a previous opening is kept between requests.
 */
final class ShortLinkOpening
{
	private const CODE_PATTERN = '/^[0-9a-f]{32}$/D';
	/** The sources of a public link, which give no right of the person's own. */
	private const PUBLIC_LINK_SOURCES = [
		UnifiedLinkAccessSource::ExternalLink,
		UnifiedLinkAccessSource::ParentExternalLink,
	];
	/** The path where the request has no named route of the short entry, as a command line or an agent. */
	private const FALLBACK_PATH_PREFIX = '/docs/pub/s/';

	private function __construct(
		private readonly ExternalLink $externalLink,
		private readonly File $file,
		private readonly ?Version $version,
	)
	{
	}

	public static function isValidCode(string $code): bool
	{
		return preg_match(self::CODE_PATTERN, $code) === 1;
	}

	/**
	 * Returns the opening of the code or null for any refusal, which callers answer with one and the same denial.
	 */
	public static function resolve(string $code): ?self
	{
		if (!self::isValidCode($code))
		{
			return null;
		}

		$externalLink = ExternalLink::load(['=CODE' => $code]);
		if (
			!$externalLink instanceof ExternalLink
			// The collation of the column may ignore the case, the code never does.
			|| $externalLink->getShortCode() !== $code
			|| !$externalLink->canBeOpenedByShortCode()
			|| $externalLink->isExpired()
		)
		{
			return null;
		}

		$file = $externalLink->getObject();
		if (!$file instanceof File || $file->isDeleted() || $file->isRoot())
		{
			return null;
		}

		$openPolicy = ServiceLocator::getInstance()->get(ExternalLinkOpenPolicy::class);
		if (!$openPolicy->canOpenByPortalPolicy($externalLink))
		{
			return null;
		}

		$version = null;
		if ($externalLink->isSpecificVersion())
		{
			// A removed revision is not replaced by the current one.
			$version = Version::loadById((int)$externalLink->getVersionId());
			if (!$version instanceof Version || (int)$version->getObjectId() !== (int)$file->getRealObjectId())
			{
				return null;
			}
		}

		return new self($externalLink, $file, $version);
	}

	public function getExternalLink(): ExternalLink
	{
		return $this->externalLink;
	}

	public function getFile(): File
	{
		return $this->file;
	}

	public function getVersion(): ?Version
	{
		return $this->version;
	}

	public function getCode(): string
	{
		return (string)$this->externalLink->getShortCode();
	}

	/**
	 * The address the opening stays on: every redirect and form of the opening leads back here.
	 */
	public function getCanonicalUrl(): string
	{
		$code = $this->getCode();

		return Driver::getInstance()->getUrlManager()->getShortPublicLinkPath($code)
			?? self::FALLBACK_PATH_PREFIX . $code . '/'
		;
	}

	/**
	 * The canonical address on the origin the page is served from, the one to copy and to share.
	 */
	public function getAbsoluteCanonicalUrl(): string
	{
		$hostUrl = Driver::getInstance()->getUrlManager()->getHostUrl();
		if (Loader::includeModule('bitrix24') && !\CBitrix24::isCustomDomain())
		{
			return 'https://bitrix24public.com/' . parse_url($hostUrl, PHP_URL_HOST) . $this->getCanonicalUrl();
		}

		return $hostUrl . $this->getCanonicalUrl();
	}

	/**
	 * Resolves the opening again right before an editor builds its config: an opening rendered a moment ago
	 * is not enough. The link must be the same and still alive, the session must open its file and revision,
	 * and the password of the link must be confirmed by everyone it is asked of: a guest, and an extranet user
	 * out of a collab. A guest edits by the mode of the link; an employee edits only in the mode the opening was
	 * allowed and while his effective right is edit. Null is a refusal.
	 *
	 * @param string|null $allowedMode The mode the entry allowed an employee; a guest has the mode of the link.
	 * @param int $userId The employee; 0 is the user of the current request.
	 */
	public function revalidateForEditor(
		DocumentSession $documentSession,
		?string $allowedMode,
		bool $isGuest,
		int $userId = 0,
	): ?self
	{
		$fresh = self::resolve($this->getCode());
		if (
			$fresh === null
			|| (int)$fresh->externalLink->getId() !== (int)$this->externalLink->getId()
			|| (int)$documentSession->getObject()?->getRealObjectId() !== (int)$fresh->file->getRealObjectId()
			|| (int)$documentSession->getVersionId() !== (int)$fresh->version?->getId()
		)
		{
			return null;
		}

		if ($isGuest)
		{
			$passwordConfirmed = !$fresh->externalLink->hasPassword()
				|| ServiceLocator::getInstance()->get(ExternalLinkPasswordService::class)->isConfirmed(
					$fresh->externalLink,
				)
			;
			$mayOpen = $passwordConfirmed && (!$documentSession->isEdit() || $fresh->allowsEditBySource());

			return $mayOpen ? $fresh : null;
		}

		// The check of the source asks the password by the rule of the access factory.
		$effectiveAccessLevel = $fresh->getEffectiveAccessLevel($userId > 0 ? $userId : self::currentUserId());
		if (
			$effectiveAccessLevel === null
			|| (
				$documentSession->isEdit()
				&& ($allowedMode !== 'edit' || $effectiveAccessLevel !== UnifiedLinkAccessLevel::Edit)
			)
		)
		{
			return null;
		}

		return $fresh;
	}

	/**
	 * The right of the person of his own: the rights of the object, its unified link and the unified links of its
	 * folders, without any public link of the file or of its folders.
	 */
	public function getOwnAccessLevel(int $userId): UnifiedLinkAccessLevel
	{
		if ($userId <= 0 || !User::loadById($userId) instanceof User)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		$accessResult = self::getAccessService()->checkAll($this->file, null, $userId);
		$ownAccessLevel = UnifiedLinkAccessLevel::Denied;
		foreach (UnifiedLinkAccessSource::cases() as $source)
		{
			if (in_array($source, self::PUBLIC_LINK_SOURCES, true))
			{
				continue;
			}

			$accessLevel = $accessResult->getAccessLevel($source);
			if ($accessLevel->value > $ownAccessLevel->value)
			{
				$ownAccessLevel = $accessLevel;
			}
		}

		return $ownAccessLevel;
	}

	/**
	 * The right the opening gives the person: the higher of his own right and the right of the source, while the
	 * source passes its check - the binding to the file and the revision, the term and the password by the rule
	 * of the access factory. A source that fails gives null and never falls back to the own right. An exact
	 * revision is read-only.
	 */
	public function getEffectiveAccessLevel(int $userId): ?UnifiedLinkAccessLevel
	{
		if ($userId > 0 && !User::loadById($userId) instanceof User)
		{
			return null;
		}

		$sourceAccessLevel = self::getAccessService()->check(
			$this->file,
			userId: $userId,
			externalLink: $this->externalLink,
			version: $this->version,
		);
		if ($sourceAccessLevel === UnifiedLinkAccessLevel::Denied)
		{
			return null;
		}

		$ownAccessLevel = $this->getOwnAccessLevel($userId);
		$effectiveAccessLevel = $ownAccessLevel->value > $sourceAccessLevel->value
			? $ownAccessLevel
			: $sourceAccessLevel
		;

		return $this->version !== null && $effectiveAccessLevel === UnifiedLinkAccessLevel::Edit
			? UnifiedLinkAccessLevel::Read
			: $effectiveAccessLevel
		;
	}

	/**
	 * The mode the link itself grants: edit by an edit link of the live file, view otherwise. An exact revision
	 * is always read-only.
	 */
	public function allowsEditBySource(): bool
	{
		return $this->version === null && $this->externalLink->allowEdit();
	}

	/**
	 * The unique code of the real file for the native viewer of the mobile application, or null. By the code the
	 * unified address of the file gives what the chain of rights of the file gives, so the code goes out only
	 * while that equals the effective right of the opening and falls to the own right once the source is gone.
	 */
	public function getNativeUniqueCode(int $userId): ?string
	{
		if ($userId <= 0 || $this->version !== null)
		{
			return null;
		}

		$effectiveAccessLevel = $this->getEffectiveAccessLevel($userId);
		$realFile = $this->file->getRealObject();
		if ($effectiveAccessLevel === null || !$realFile instanceof File || !$this->isLatestLinkOf($realFile))
		{
			return null;
		}

		// The support is checked only when the address is issued and may be lost later; without it the download
		// link of the file is not signed for the unified link, and a holder by the link alone could not download.
		if (!$realFile->supportsUnifiedLink())
		{
			return null;
		}

		$ownAccessLevel = $this->getOwnAccessLevel($userId);
		$editsByLinkAlone =
			$effectiveAccessLevel === UnifiedLinkAccessLevel::Edit
			&& $ownAccessLevel !== UnifiedLinkAccessLevel::Edit
		;
		if (
			$editsByLinkAlone
			|| !$this->allowsDownload($effectiveAccessLevel, $ownAccessLevel)
			|| self::isDrawnByRightsOfFile($this->file)
			|| self::getAccessService()->check($realFile, null, $userId) !== $effectiveAccessLevel
			|| $this->hasOtherPublicLinks($realFile, $userId)
		)
		{
			return null;
		}

		$uniqueCode = (string)$realFile->getUniqueCode();

		return $uniqueCode !== '' ? $uniqueCode : null;
	}

	/**
	 * The unified address of a file opens by its latest live manual link, which a link of a symbolic link never is.
	 */
	private function isLatestLinkOf(File $realFile): bool
	{
		$latestLink = ServiceLocator::getInstance()
			->get(ExternalLinkProvider::class)
			->getForUnifiedLinkAccessCheck((int)$realFile->getId())
		;

		return (int)$latestLink?->getId() === (int)$this->externalLink->getId();
	}

	/**
	 * The download rule of the security context of the storage: an edit downloads, a reading of his own by the
	 * option of the object, a reading by the source by the setting of the link and its password.
	 */
	private function allowsDownload(
		UnifiedLinkAccessLevel $effectiveAccessLevel,
		UnifiedLinkAccessLevel $ownAccessLevel,
	): bool
	{
		if ($effectiveAccessLevel === UnifiedLinkAccessLevel::Edit)
		{
			return true;
		}

		$downloadAccess = ServiceLocator::getInstance()->get(DownloadAccess::class);

		// Below edit the source reads: a source that fails leaves no effective right at all.
		return ($ownAccessLevel === UnifiedLinkAccessLevel::Read && $downloadAccess->checkObjectOption($this->file))
			|| $downloadAccess->checkExternalLink($this->file, $this->externalLink)
		;
	}

	/**
	 * A board opens for editing by the rights of the file and an html page is drawn by them. The viewers know
	 * a board by its type, which a rename keeps, and the name alone may make any file a board.
	 */
	private static function isDrawnByRightsOfFile(File $file): bool
	{
		return (int)$file->getTypeFile() === TypeFile::BOARD
			|| TypeFile::isBoard($file)
			|| HtmlViewerPolicy::isViewable($file)
		;
	}

	/**
	 * Another live manual link of the file, of any scenario, or a public link of a folder above it: by the unified
	 * address the holder gets its right, and the right of the next link once the source is taken back.
	 */
	private function hasOtherPublicLinks(File $realFile, int $userId): bool
	{
		$externalLinks = ServiceLocator::getInstance()
			->get(ExternalLinkRepositoryInterface::class)
			->getAllForUse((int)$realFile->getId())
		;
		foreach ($externalLinks as $externalLink)
		{
			if ((int)$externalLink->getId() !== (int)$this->externalLink->getId())
			{
				return true;
			}
		}

		return self::getAccessService()
			->checkAll($realFile, null, $userId)
			->getAccessLevel(UnifiedLinkAccessSource::ParentExternalLink) !== UnifiedLinkAccessLevel::Denied
		;
	}

	private static function getAccessService(): UnifiedLinkAccessService
	{
		return ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
	}

	private static function currentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
