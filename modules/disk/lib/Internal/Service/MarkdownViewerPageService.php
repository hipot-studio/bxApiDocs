<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionService;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContext;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSignature;
use Bitrix\Disk\ObjectLock;
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
 */
class MarkdownViewerPageService
{
	private const COMPONENT_NAME = 'bitrix:disk.file.viewer-markdown';

	public function __construct(
		private readonly UnifiedLinkSignature $signature = new UnifiedLinkSignature(),
		private ?OpeningContextFactory $openingContextFactory = null,
		private ?HeaderCompositionService $compositionService = null,
		private ?UnifiedLinkAccessService $accessService = null,
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

		return [
			'NAME' => $name,
			'CONTENT_URL' => $contentUrl,
			'DOWNLOAD_URL' => $downloadUrl,
			'PORTAL_URL' => $this->getPortalUrl(),
			'HEADER_COMPOSITION' => $this->composeHeader(
				$this->getOpeningContextFactory()->fromFile($file, $userId, $downloadUrl, $accessLevel),
				$userId,
				$name,
				$downloadUrl,
			),
			'DOCUMENT_STATE' => $this->buildDocumentState(
				$file,
				$version === null ? null : (int)$version->getId(),
				$accessLevel->canEdit(),
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
				$file instanceof File ? $file : null,
				(int)$version->getId(),
				false,
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
	 * What the page knows of the document beyond the header. Nothing on the page draws it yet: it is the
	 * ground an editing mode of the page would stand on.
	 *
	 * @return array{versionId: ?int, canEdit: bool, lock: array{isLocked: bool, lockedBy: ?int}}
	 */
	private function buildDocumentState(?File $file, ?int $versionId, bool $canEdit): array
	{
		$lock = $file !== null && $this->isObjectLockEnabled() ? $file->getLock() : null;

		return [
			'versionId' => $versionId,
			'canEdit' => $canEdit,
			'lock' => [
				'isLocked' => $lock instanceof ObjectLock,
				'lockedBy' => $lock instanceof ObjectLock ? (int)$lock->getCreatedBy() : null,
			],
		];
	}

	/**
	 * The revision the file shows now. Revisions are ordered by the time they were created, which has a
	 * precision of a second, so two of them uploaded within one second are told apart by their ids -
	 * File::getLastVersion() leaves such a tie to the database.
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
