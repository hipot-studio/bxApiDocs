<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Enum\DocumentHeaderElement;
use Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionService;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContext;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory;
use Bitrix\Disk\Internal\Service\HtmlViewer\PublicDomainAccessContext;
use Bitrix\Disk\Internal\Service\HtmlViewer\PublicDomainAccessTokenService;
use Bitrix\Disk\Internal\Service\HtmlViewer\PublicDomainUrlResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkSignature;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
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
 * The external link branch stands apart: it serves whoever holds the link, so it is built from the
 * link itself and offers no way back into the portal.
 *
 * HEADER_COMPOSITION describes the header of the very opening the page was built for, and it is the
 * only thing that header is drawn from: this page holds no session of a document, and the action of
 * the controller which answers for one is asked by the session hash the page has none of. So an
 * opening that could not be described is not fetched again but falls back to the minimal header the
 * page can fill on its own, and the refusal behind it is logged all the same {@see composeHeader()}.
 * Everything the header hands out - the downloading, the access of the object and the copying of the
 * link - is an element of that description and is passed inside it.
 */
class HtmlViewerPageService
{
	private bool $publicDomainAccessContextResolved = false;
	private ?PublicDomainAccessContext $publicDomainAccessContext = null;

	public function __construct(
		private readonly UnifiedLinkSignature $signature = new UnifiedLinkSignature(),
		private ?OpeningContextFactory $openingContextFactory = null,
		private ?HeaderCompositionService $compositionService = null,
		private ?PublicDomainUrlResolver $publicDomainUrlResolver = null,
		private ?PublicDomainAccessTokenService $publicDomainAccessTokenService = null,
	)
	{
	}

	public function buildParamsByFile(File $file, ?UnifiedLinkAccessLevel $accessLevel = null): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$signature = $this->signFile($file);
		$downloadUrl = $this->sign($urlManager->getUrlForDownloadFile($file), $signature);
		$userId = $this->getCurrentUserId();
		$name = (string)$file->getName();

		return $this->preparePublicDomainParams([
			'NAME' => $name,
			'CONTENT_URL' => $this->sign($urlManager->getUrlForShowHtml((int)$file->getId()), $signature),
			'PORTAL_URL' => $this->getPortalUrl(),
			'HEADER_COMPOSITION' => $this->composeHeader(
				$this->getOpeningContextFactory()->fromFile($file, $userId, $downloadUrl, $accessLevel),
				$userId,
				$name,
				$downloadUrl,
			),
		], $file->getRealObject());
	}

	public function buildParamsByVersion(Version $version, ?UnifiedLinkAccessLevel $accessLevel = null): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$signature = $this->signVersion($version);
		$contentUrl = $urlManager->getUrlForShowHtmlVersion((int)$version->getId());
		$downloadUrl = $urlManager->getUrlForDownloadVersion($version);
		if ($signature !== null)
		{
			$contentUrl = $this->sign($contentUrl, $signature);
			$downloadUrl = $this->sign($downloadUrl, $signature);
		}
		$userId = $this->getCurrentUserId();
		$name = (string)$version->getName();

		return $this->preparePublicDomainParams([
			'NAME' => $name,
			'CONTENT_URL' => $contentUrl,
			'PORTAL_URL' => $this->getPortalUrl(),
			'HEADER_COMPOSITION' => $this->composeHeader(
				$this->getOpeningContextFactory()->fromVersion($version, $userId, $downloadUrl, $accessLevel),
				$userId,
				$name,
				$downloadUrl,
			),
		]);
	}

	public function buildParamsByAttachedObject(
		AttachedObject $attached,
		?UnifiedLinkAccessLevel $accessLevel = null,
	): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$downloadUrl = $urlManager->getUrlUfController('download', ['attachedId' => (int)$attached->getId()]);
		$userId = $this->getCurrentUserId();
		$name = (string)$attached->getName();

		return $this->preparePublicDomainParams([
			'NAME' => $name,
			'CONTENT_URL' => $urlManager->getUrlForShowHtmlAttached((int)$attached->getId()),
			'PORTAL_URL' => $this->getPortalUrl(),
			'HEADER_COMPOSITION' => $this->composeHeader(
				$this->getOpeningContextFactory()->fromAttachedObject($attached, $userId, $downloadUrl, $accessLevel),
				$userId,
				$name,
				$downloadUrl,
			),
		]);
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
	 * The portal url is not offered here, not even when a portal user lands on this page through a
	 * password-protected link, which is served in external mode too.
	 *
	 * The description of the header says the same in its own terms: an unlinked logo, no access
	 * control and no identifiers of the object inside the portal. The copy button stays and hands out
	 * the public link itself - the only address this reader can open at all (D-1).
	 */
	public function buildParamsByExternalLink(
		File $file,
		ExternalLink $link,
		string $token,
		?Version $pinnedVersion,
	): array
	{
		$urlManager = Driver::getInstance()->getUrlManager();
		$downloadUrl = $urlManager->getUrlExternalLink([
			'hash' => $link->getHash(),
			'action' => 'download',
			'token' => $token,
		]);
		$userId = $this->getCurrentUserId();
		$name = $this->resolveExternalLinkName($file, $pinnedVersion);

		return [
			'NAME' => $name,
			'CONTENT_URL' => $urlManager->getUrlExternalLink([
				'hash' => $link->getHash(),
				'action' => 'showHtml',
				'token' => $token,
			]),
			'HEADER_COMPOSITION' => $this->composeHeader(
				$this->getOpeningContextFactory()->fromExternalLink($file, $link, $pinnedVersion, $userId, $downloadUrl),
				$userId,
				$name,
				$downloadUrl,
			),
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

	private function preparePublicDomainParams(array $params, ?BaseObject $unifiedLinkObject = null): array
	{
		$accessToken = $this->getPublicDomainAccessToken();
		if ($accessToken === null)
		{
			return $params;
		}

		$params['CONTENT_URL'] = (string)(new Uri($params['CONTENT_URL']))->addParams([
			'access_token' => $accessToken,
		]);
		$params['PORTAL_URL'] = $this->getCanonicalUrl($params['PORTAL_URL']);

		$composition = $params['HEADER_COMPOSITION'];
		if (!is_array($composition))
		{
			return $params;
		}

		$composition['elements'] = array_values(array_filter(
			(array)$composition['elements'],
			static fn(string $element): bool => $element !== DocumentHeaderElement::Access->value,
		));
		$composition['urls']['download'] = $this->getCanonicalUrl($composition['urls']['download']);

		if ($unifiedLinkObject !== null && $composition['urls']['unifiedLink'] !== '')
		{
			$relativeUnifiedLink = Driver::getInstance()->getUrlManager()->getUnifiedLink($unifiedLinkObject);
			$composition['urls']['unifiedLink'] = $this->getCanonicalUrl($relativeUnifiedLink);
		}

		$params['HEADER_COMPOSITION'] = $composition;

		return $params;
	}

	protected function getPublicDomainAccessToken(): ?string
	{
		$request = Application::getInstance()->getContext()->getRequest();
		$accessToken = $request->getQuery('access_token');

		return $this->getPublicDomainAccessContext() !== null && is_string($accessToken) && $accessToken !== ''
			? $accessToken
			: null
		;
	}

	protected function getPublicDomainUserId(): ?int
	{
		return $this->getPublicDomainAccessContext()?->getUserId();
	}

	private function getPublicDomainAccessContext(): ?PublicDomainAccessContext
	{
		if ($this->publicDomainAccessContextResolved)
		{
			return $this->publicDomainAccessContext;
		}

		$this->publicDomainAccessContextResolved = true;
		$request = Application::getInstance()->getContext()->getRequest();
		$accessToken = $request->getQuery('access_token');
		if ($request->getQuery('public_domain') !== 'Y' || !is_string($accessToken) || $accessToken === '')
		{
			return null;
		}

		$this->publicDomainAccessTokenService ??= new PublicDomainAccessTokenService();
		$this->publicDomainAccessContext = $this->publicDomainAccessTokenService->decodeToken($accessToken);

		return $this->publicDomainAccessContext;
	}

	private function getCanonicalUrl(string $relativeUrl): string
	{
		return $this->getPublicDomainUrlResolver()->getCanonicalUrl($relativeUrl) ?? $relativeUrl;
	}

	private function getPublicDomainUrlResolver(): PublicDomainUrlResolver
	{
		return $this->publicDomainUrlResolver ??= PublicDomainUrlResolver::createFromPortalConfiguration();
	}

	/**
	 * The header is drawn from the description the page carries, so the page it belongs to computes it -
	 * on the request which already authorized the content, and never through an entry of its own.
	 *
	 * A description that could not be built is not refetched: this surface holds no session of a document
	 * to fetch one by. The page keeps serving the content, and the header keeps the part which stands on
	 * what the page holds itself {@see HeaderCompositionService::composeMinimalForPage()} - the reading
	 * was already confirmed by the very answer that served the page, and no element of that minimum asks
	 * about rights the refused description would have decided. The refusal is answered to nobody here, so
	 * it is logged whichever header comes out of it - that is the only trace such a page leaves at all.
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
			new \RuntimeException('The html viewer page was built with no description of its header.'),
		);
	}

	/**
	 * One instance per request, shared with every other consumer of the locator. Asked for only where a
	 * description is built, so nothing else of the page pays for resolving it.
	 */
	private function getCompositionService(): HeaderCompositionService
	{
		return $this->compositionService ??= ServiceLocator::getInstance()->get(HeaderCompositionService::class);
	}

	/**
	 * One instance per request, shared with every other consumer of the locator.
	 */
	private function getOpeningContextFactory(): OpeningContextFactory
	{
		return $this->openingContextFactory ??= ServiceLocator::getInstance()->get(OpeningContextFactory::class);
	}

	private function getCurrentUserId(): int
	{
		return $this->getPublicDomainUserId() ?? (int)CurrentUser::get()->getId();
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
