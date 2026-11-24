<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionService;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContext;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory;
use Bitrix\Disk\Internal\Service\MarkdownEditor\EditPolicy;
use Bitrix\Disk\Internal\Service\MarkdownEditor\PreviewPolicy;
use Bitrix\Disk\Internal\Service\MarkdownEditor\Readiness;
use Bitrix\Disk\Internal\Service\MarkdownEditor\ReadinessService;
use Bitrix\Disk\Internal\Service\MarkdownEditor\SessionService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSignature;
use Bitrix\Disk\ObjectLock;
use Bitrix\Disk\Realtime\Tags\ObjectTag;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Web\Uri;

/**
 * Builds the params of the Markdown page (header + the document the page asks for on its own). They are
 * fed verbatim to the bitrix:disk.file.viewer-markdown component, hence the component param naming.
 *
 * The page holds no document: CONTENT_URL is the showMarkdown action the page asks, and the answer of that
 * action is what it shows. The page of a file is bound to the revision it was built on - the content, the
 * download of the page and the download of the header all name that revision, so a revision uploaded
 * after the page was opened changes none of them. A file without any revision falls back to the endpoints
 * of the file itself, which serve whatever is current at the moment they are asked.
 *
 * The urls carry the `_uls` signature that CheckReadPermission accepts together with the unified link
 * check, as on the html viewer page {@see HtmlViewerPageService}: the one of a revision names the pair it
 * belongs to, the one of a file names the file.
 *
 * The header is drawn from HEADER_COMPOSITION only, and an opening that could not be described falls
 * back to the minimal header and is logged, as on the html viewer page. Errors of rendering the document
 * are answered to the page by the action itself and are not logged here.
 *
 * While the policy of editing allows the file {@see EditPolicy}, the page of the file is also the editor of it:
 * the verdict on entering editing {@see ReadinessService::assess()} is taken once and feeds both the header and
 * DOCUMENT_STATE (DTO-02), and the viewer is subscribed to the events of the object (PULL-01).
 */
class MarkdownViewerPageService
{
	private const COMPONENT_NAME = 'bitrix:disk.file.viewer-markdown';

	public function __construct(
		private readonly UnifiedLinkSignature $signature = new UnifiedLinkSignature(),
		private ?OpeningContextFactory $openingContextFactory = null,
		private ?HeaderCompositionService $compositionService = null,
		private ?UnifiedLinkAccessService $accessService = null,
		private ?ReadinessService $readinessService = null,
	)
	{
	}

	public function buildParamsByFile(File $file, ?UnifiedLinkAccessLevel $accessLevel = null): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$userId = $this->getCurrentUserId();
		$name = (string)$file->getName();
		// Decided once and handed to the description as well, so the chain is not walked twice.
		$accessLevel ??= $this->getAccessService()->check($file, userId: $userId);

		$version = $this->getNewestRevision($file);
		if ($version instanceof Version)
		{
			$signature = $this->signature->getUlsForVersion((int)$version->getObjectId(), (int)$version->getId());
			$contentUrl = $urlManager->getUrlForShowMarkdownVersion((int)$version->getId());
			$downloadUrl = $urlManager->getUrlForDownloadVersion($version);
		}
		else
		{
			$signature = $this->signature->getUlsForObjectId((int)$file->getId());
			$contentUrl = $urlManager->getUrlForShowMarkdown($file);
			$downloadUrl = $urlManager->getUrlForDownloadFile($file);
		}
		$contentUrl = $this->sign($contentUrl, $signature);
		$downloadUrl = $this->sign($downloadUrl, $signature);

		$isEditable = EditPolicy::isEditableSource($file);
		// The page is reached by the unified link of the file, so its code is what the page presents.
		$uniqueCode = (string)$file->getRealObject()?->getUniqueCode();
		$readiness = $isEditable ? $this->assess($file, $userId, $uniqueCode === '' ? null : $uniqueCode) : null;
		$openingContextFactory = $this->getOpeningContextFactory();
		$openingContext = $readiness === null
			? $openingContextFactory->fromFile($file, $userId, $downloadUrl, $accessLevel)
			: $openingContextFactory->fromMarkdownFile($file, $userId, $downloadUrl, $accessLevel, $readiness)
		;

		return [
			'NAME' => $name,
			'CONTENT_URL' => $contentUrl,
			'DOWNLOAD_URL' => $downloadUrl,
			'PORTAL_URL' => $this->getPortalUrl(),
			'HEADER_COMPOSITION' => $this->composeHeader($openingContext, $userId, $name, $downloadUrl),
			'DOCUMENT_STATE' => $this->buildDocumentState(
				file: $file,
				versionId: $version === null ? null : (int)$version->getId(),
				readiness: $readiness,
				edit: $readiness?->hasRight ? $this->buildEditOptions($file, $uniqueCode) : null,
				pullTag: $isEditable ? $this->subscribeViewer($file, $userId) : null,
			),
		];
	}

	/**
	 * The page of a public link (API-02): the document and its download are the actions of the link itself,
	 * authorized by the token the link page stored in the session, so a guest without an account reads it.
	 * A link pinned to a revision shows that revision for reading only; a link with the right to edit makes the
	 * page the editor of the file, and the actions of editing are told the link by its hash. The pull of a page
	 * reached by a public link is not set up: the guest gets none, and the link grants no watch of the object.
	 */
	public function buildParamsByExternalLink(File $file, ExternalLink $link, string $token, ?Version $pinned): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$userId = $this->getCurrentUserId();
		$name = (string)($pinned?->getName() ?? $file->getName());
		$contentUrl = $urlManager->getUrlExternalLink([
			'hash' => $link->getHash(),
			'action' => 'showMarkdown',
			'token' => $token,
		]);
		$downloadUrl = $urlManager->getUrlExternalLink([
			'hash' => $link->getHash(),
			'action' => 'download',
			'token' => $token,
		]);

		$readiness = EditPolicy::isEditableSource($file, $pinned)
			? $this->assessByExternalLink($file, $userId, $link)
			: null
		;
		$version = $pinned ?? $this->getNewestRevision($file);

		return [
			'NAME' => $name,
			'CONTENT_URL' => $contentUrl,
			'DOWNLOAD_URL' => $downloadUrl,
			'PORTAL_URL' => $this->getPortalUrl(),
			'HEADER_COMPOSITION' => $this->composeHeader(
				$this->getOpeningContextFactory()->fromExternalLink(
					$file,
					$link,
					$pinned,
					$userId,
					$downloadUrl,
					readiness: $readiness,
				),
				$userId,
				$name,
				$downloadUrl,
			),
			'DOCUMENT_STATE' => $this->buildDocumentState(
				file: $file,
				versionId: $version === null ? null : (int)$version->getId(),
				readiness: $readiness,
				edit: $readiness?->hasRight
					? $this->buildEditOptions($file, '', (string)$link->getHash())
					: null,
				isLockOwnerHidden: true,
			),
		];
	}

	/**
	 * A revision is opened for reading only, and the header copies the address of that very revision.
	 * An orphaned revision is left unsigned: its endpoints refuse it, and the page shows the error.
	 */
	public function buildParamsByVersion(Version $version, ?UnifiedLinkAccessLevel $accessLevel = null): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$contentUrl = $urlManager->getUrlForShowMarkdownVersion((int)$version->getId());
		$downloadUrl = $urlManager->getUrlForDownloadVersion($version);
		$file = $version->getObject();
		if ($file instanceof File)
		{
			$signature = $this->signature->getUlsForVersion((int)$file->getId(), (int)$version->getId());
			$contentUrl = $this->sign($contentUrl, $signature);
			$downloadUrl = $this->sign($downloadUrl, $signature);
		}
		$userId = $this->getCurrentUserId();
		$name = (string)$version->getName();

		return [
			'NAME' => $name,
			'CONTENT_URL' => $contentUrl,
			'DOWNLOAD_URL' => $downloadUrl,
			'PORTAL_URL' => $this->getPortalUrl(),
			'HEADER_COMPOSITION' => $this->composeHeader(
				$this->getOpeningContextFactory()->fromVersion(
					$version,
					$userId,
					$downloadUrl,
					$accessLevel,
					offersRevisionLink: true,
				),
				$userId,
				$name,
				$downloadUrl,
			),
			'DOCUMENT_STATE' => $this->buildDocumentState(
				file: $file instanceof File ? $file : null,
				versionId: (int)$version->getId(),
			),
		];
	}

	/**
	 * Wraps the component in bitrix:ui.sidepanel.wrapper to get a full HTML document, the same shell the
	 * html viewer page uses {@see HtmlViewerPageService::renderPage()}.
	 *
	 * An empty string means the shell produced nothing: the caller turns it into an error instead of
	 * serving a blank page.
	 */
	public function renderPage(array $componentParams): string
	{
		return (string)$GLOBALS['APPLICATION']->includeComponent('bitrix:ui.sidepanel.wrapper', '', [
			'RETURN_CONTENT' => true,
			'POPUP_COMPONENT_NAME' => self::COMPONENT_NAME,
			'POPUP_COMPONENT_TEMPLATE_NAME' => '',
			'POPUP_COMPONENT_PARAMS' => $componentParams,
			'PLAIN_VIEW' => true,
			'IFRAME_MODE' => true,
			'PREVENT_LOADING_WITHOUT_IFRAME' => false,
			'USE_PADDING' => false,
		]);
	}

	/**
	 * What the page knows of the document beyond the header (DTO-02): the revision it was built on, whether
	 * the viewer may enter editing now and why not, what the editing mode stands on, and the tag of the object
	 * the viewer listens to. A refusal is named only to a viewer holding the right; a revision, a viewer without
	 * the right and a portal with the editing off get no editing block at all.
	 *
	 * @return array{
	 *     versionId: ?int,
	 *     canEdit: bool,
	 *     lock: array{isLocked: bool, lockedBy: ?int},
	 *     editRefusal: array{code: string, message: string, customData: ?array}|null,
	 *     edit: array|null,
	 *     pullTag: ?string,
	 * }
	 */
	private function buildDocumentState(
		?File $file,
		?int $versionId,
		?Readiness $readiness = null,
		?array $edit = null,
		?string $pullTag = null,
		bool $isLockOwnerHidden = false,
	): array
	{
		$lock = $file !== null && $this->isObjectLockEnabled() ? $file->getLock() : null;
		// a visitor of a public link learns nothing of the people of the portal, the id of the lock holder included
		$lockedBy = $lock instanceof ObjectLock && !$isLockOwnerHidden ? (int)$lock->getCreatedBy() : null;
		$refusal = $readiness?->hasRight ? $readiness->refusal : null;

		return [
			'versionId' => $versionId,
			'canEdit' => (bool)$readiness?->canEdit,
			'lock' => [
				'isLocked' => $lock instanceof ObjectLock,
				'lockedBy' => $lockedBy,
			],
			'editRefusal' => $refusal === null ? null : [
				'code' => (string)$refusal->getCode(),
				'message' => (string)$refusal->getMessage(),
				'customData' => $refusal->getCustomData(),
			],
			'edit' => $edit,
			'pullTag' => $pullTag,
		];
	}

	/**
	 * What the editing mode of the page stands on: the file the actions name, the code of its unified link or the
	 * hash of the public link the actions present as the page does, the limits and the clock of the md session, and
	 * whether the preview of the text being edited is offered.
	 */
	private function buildEditOptions(File $file, string $uniqueCode, string $externalLinkHash = ''): array
	{
		return [
			'fileId' => (int)$file->getId(),
			'uniqueCode' => $uniqueCode === '' ? null : $uniqueCode,
			'externalLinkHash' => $externalLinkHash === '' ? null : $externalLinkHash,
			'maxSize' => Configuration::getMaxSizeForMarkdownRender(),
			'previewMaxSize' => Configuration::getMaxSizeForMarkdownPreview(),
			'heartbeatSeconds' => SessionService::HEARTBEAT_SECONDS,
			'livenessSeconds' => SessionService::LIVENESS_SECONDS,
			'idleSeconds' => SessionService::IDLE_SECONDS,
			'preview' => PreviewPolicy::isEnabled(),
		];
	}

	/**
	 * Subscribes the viewer to the events of the object, so a revision written by somebody else reaches the
	 * open page. A guest is not subscribed: the pull of a guest is suppressed.
	 *
	 * @return string|null the tag the client keeps the watch of, null when nobody was subscribed
	 */
	private function subscribeViewer(File $file, int $userId): ?string
	{
		if ($this->isGuest($userId))
		{
			return null;
		}

		$tag = new ObjectTag((int)$file->getRealObjectId());
		$this->subscribe($tag, $userId);

		return $tag->getName();
	}

	protected function subscribe(ObjectTag $tag, int $userId): void
	{
		$tag->subscribe($userId);
	}

	protected function isGuest(int $userId): bool
	{
		return $userId <= 0 || DocumentEditorUser::isCurrentUserDocumentEditor();
	}

	protected function assess(File $file, int $userId, ?string $uniqueCode): Readiness
	{
		return $this->getReadinessService()->assess($file, $userId, $uniqueCode);
	}

	protected function assessByExternalLink(File $file, int $userId, ExternalLink $link): Readiness
	{
		return $this->getReadinessService()->assess($file, $userId, link: $link);
	}

	private function getReadinessService(): ReadinessService
	{
		return $this->readinessService ??= new ReadinessService();
	}

	/**
	 * The revision the file shows now. Revisions are ordered by the time they were created, which has a
	 * precision of a second, so two of them uploaded within one second are told apart by their ids - the
	 * order File::getVersions() takes by default, stated here so the page does not depend on that default.
	 */
	private function getNewestRevision(File $file): ?Version
	{
		$versions = $file->getVersions([
			'order' => ['CREATE_TIME' => 'DESC', 'ID' => 'DESC'],
			'limit' => 1,
		]);
		$version = reset($versions);

		return $version instanceof Version ? $version : null;
	}

	protected function isObjectLockEnabled(): bool
	{
		return (bool)Configuration::isEnabledObjectLock();
	}

	/**
	 * Where the logo leads back to - the root of the current site.
	 */
	private function getPortalUrl(): string
	{
		return defined('SITE_DIR') ? (string)SITE_DIR : '/';
	}

	/**
	 * A description that could not be built is not refetched: the page holds no session of a document to
	 * fetch one by. The header keeps the part which stands on what the page holds itself, and the refusal
	 * is logged - the only trace such a page leaves.
	 */
	private function composeHeader(
		?OpeningContext $openingContext,
		int $userId,
		string $name,
		string $downloadUrl,
	): ?array
	{
		$composition = $this->getCompositionService()->composeForPage($openingContext, $userId);
		if ($composition !== null)
		{
			return $composition;
		}

		$this->logMissingComposition();

		return $this->getCompositionService()->composeMinimalForPage($name, $downloadUrl);
	}

	protected function logMissingComposition(): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog(
			new \RuntimeException('The markdown viewer page was built with no description of its header.'),
		);
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	private function getCompositionService(): HeaderCompositionService
	{
		return $this->compositionService ??= ServiceLocator::getInstance()->get(HeaderCompositionService::class);
	}

	private function getOpeningContextFactory(): OpeningContextFactory
	{
		return $this->openingContextFactory ??= ServiceLocator::getInstance()->get(OpeningContextFactory::class);
	}

	private function getAccessService(): UnifiedLinkAccessService
	{
		return $this->accessService ??= ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
	}

	private function sign(string $url, string $signature): string
	{
		return (string)(new Uri($url))->addParams(['_uls' => $signature]);
	}
}
