<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSignature;
use Bitrix\Disk\Version;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Web\Uri;

/**
 * Builds the params of the html viewer page (header + sandboxed iframe). They are fed verbatim to
 * the bitrix:disk.file.viewer-html component, hence the component param naming.
 *
 * The file and version urls carry the `_uls` signature that CheckReadPermission accepts together with
 * the unified link check: a reader holding unified link access has no direct rights on either. The
 * signature names what it opens — the file, or the pair the revision belongs to — so the one handed
 * out here opens that document and nothing else of the history. An attached object authorizes by the
 * entity it is attached to and takes no signature.
 *
 * SHARING_OBJECT_ID/SHARING_UNIQUE_CODE and COPY_LINK_URL exist in the file branch only: a version
 * has no access object of its own, and an attached object is configured where it is attached.
 *
 * The external link branch stands apart: it serves whoever holds the link, so it is built from the
 * link itself and offers neither the access popup nor a way back into the portal.
 */
class HtmlViewerPageService
{
	public function __construct(
		private readonly HtmlViewerSharingPolicy $sharingPolicy = new HtmlViewerSharingPolicy(),
		private readonly UnifiedLinkSignature $signature = new UnifiedLinkSignature(),
	)
	{
	}

	public function buildParamsByFile(File $file): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$signature = $this->signFile($file);

		return [
			'NAME' => $file->getName(),
			'CONTENT_URL' => $this->sign($urlManager->getUrlForShowHtml((int)$file->getId()), $signature),
			'DOWNLOAD_URL' => $this->sign($urlManager->getUrlForDownloadFile($file), $signature),
			'COPY_LINK_URL' => $urlManager->getUnifiedLink($file, ['absolute' => true]),
			'PORTAL_URL' => $this->getPortalUrl(),
		] + $this->buildSharingParams($file);
	}

	public function buildParamsByVersion(Version $version): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$signature = $this->signVersion($version);
		$contentUrl = $urlManager->getUrlForShowHtmlVersion((int)$version->getId());
		$downloadUrl = $urlManager->getUrlForDownloadVersion($version);

		return [
			'NAME' => $version->getName(),
			'CONTENT_URL' => $signature === null ? $contentUrl : $this->sign($contentUrl, $signature),
			'DOWNLOAD_URL' => $signature === null ? $downloadUrl : $this->sign($downloadUrl, $signature),
			'PORTAL_URL' => $this->getPortalUrl(),
		];
	}

	public function buildParamsByAttachedObject(AttachedObject $attached): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();

		return [
			'NAME' => $attached->getName(),
			'CONTENT_URL' => $urlManager->getUrlForShowHtmlAttached((int)$attached->getId()),
			'DOWNLOAD_URL' => $urlManager->getUrlUfController('download', ['attachedId' => (int)$attached->getId()]),
			'PORTAL_URL' => $this->getPortalUrl(),
		];
	}

	/**
	 * The public page of an html file, served to whoever opens the external link — an anonymous
	 * reader included. The endpoints are those of the link itself, so they authorize by its hash and
	 * download token and need no `_uls`: that signature opens the file endpoints of the portal.
	 *
	 * The urls stay relative on purpose. The page is served both from the portal host and from the
	 * public domain, and the iframe only stays inside the sandbox policy (`frame-ancestors 'self'`)
	 * while it shares the origin of the page that embeds it.
	 *
	 * Neither the access popup nor the portal url is offered here — not even when a portal user
	 * lands on this page through a password-protected link, which is served in external mode too.
	 */
	public function buildParamsByExternalLink(
		File $file,
		ExternalLink $link,
		string $token,
		?Version $pinnedVersion,
	): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();

		return [
			'NAME' => $this->resolveExternalLinkName($file, $pinnedVersion),
			'CONTENT_URL' => $urlManager->getUrlExternalLink([
				'hash' => $link->getHash(),
				'action' => 'showHtml',
				'token' => $token,
			]),
			'DOWNLOAD_URL' => $urlManager->getUrlExternalLink([
				'hash' => $link->getHash(),
				'action' => 'download',
				'token' => $token,
			]),
			'COPY_LINK_URL' => $urlManager->getPublicExternalLink($file, $link->getHash()),
		];
	}

	/**
	 * The header names what the page shows: a version-pinned link serves that revision, and a revision
	 * keeps the name it was saved under, so renaming the file does not retitle the page over it. The file
	 * answers for a link that is not pinned and for a revision that is gone — the page has nothing to show
	 * in the latter case anyway. The caller resolves the revision, which it needs for the gate as well.
	 */
	private function resolveExternalLinkName(File $file, ?Version $pinnedVersion): string
	{
		return (string)($pinnedVersion?->getName() ?? $file->getName());
	}

	/**
	 * Wraps the component in bitrix:ui.sidepanel.wrapper to get a full HTML document (real doctype/head
	 * with ShowHead + ShowTitle), the same shell the office editors use on their unified link page.
	 *
	 * An empty string means the shell produced nothing: the caller turns it into an error instead of
	 * serving a blank page.
	 */
	public function renderPage(array $componentParams): string
	{
		return (string)$GLOBALS['APPLICATION']->includeComponent('bitrix:ui.sidepanel.wrapper', '', [
			'RETURN_CONTENT' => true,
			'POPUP_COMPONENT_NAME' => 'bitrix:disk.file.viewer-html',
			'POPUP_COMPONENT_TEMPLATE_NAME' => '',
			'POPUP_COMPONENT_PARAMS' => $componentParams,
			'PLAIN_VIEW' => true,
			'IFRAME_MODE' => true,
			'PREVENT_LOADING_WITHOUT_IFRAME' => false,
			'USE_PADDING' => false,
		]);
	}

	/**
	 * Where the logo leads back to — the root of the current site. Only the portal branches call it:
	 * the external link one omits PORTAL_URL altogether and so leaves its logo unlinked, as a page
	 * reachable without a session needs — a portal address would only lead its reader to a login form.
	 */
	private function getPortalUrl(): string
	{
		return defined('SITE_DIR') ? (string)SITE_DIR : '/';
	}

	private function buildSharingParams(File $file): array
	{
		if (!$this->sharingPolicy->isAvailableForUser((int)CurrentUser::get()->getId()))
		{
			return [];
		}

		// The unified link of a file link points at its target, so the popup is opened for that same
		// object: both parameters have to describe it, the popup resolves the object by either.
		return [
			'SHARING_OBJECT_ID' => (int)$file->getRealObjectId(),
			'SHARING_UNIQUE_CODE' => (string)$file->getRealObject()?->getUniqueCode(),
		];
	}

	/**
	 * The page is reachable through the unified link only, so the signature is always attached: it is
	 * what opens the file endpoints for a reader without direct rights.
	 */
	private function signFile(File $file): string
	{
		return $this->signature->getUlsForObjectId((int)$file->getId());
	}

	/**
	 * The revision is signed as the pair it belongs to, so the signature opens that revision alone.
	 * An orphaned version has no object to authorize by and is left unsigned: its endpoints refuse it,
	 * and the reader is answered with the stub the viewer serves for anything it cannot show.
	 */
	private function signVersion(Version $version): ?string
	{
		$file = $version->getObject();

		return $file === null
			? null
			: $this->signature->getUlsForVersion((int)$file->getId(), (int)$version->getId())
		;
	}

	private function sign(string $url, string $signature): string
	{
		return (string)(new Uri($url))->addParams(['_uls' => $signature]);
	}
}
