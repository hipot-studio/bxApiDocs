<?php
declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Controller\ActionFilter\RequiredParameter;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\BaseObjectResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\ExternalLinkContext;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\ModeSelection;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\{FileTypeControl, RedirectToCorrectPrefix};
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\ActionFilter\UnifiedLinkAccessLevelRouter;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes\{RedirectToView, UrlGenerator};
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes\FileTypes;
use Bitrix\Disk\Infrastructure\Controller\UnifiedLink\Attributes\LevelAccess;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Public\Service\UnifiedLink;
use Bitrix\Disk\UrlManager;
use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Version;
use Bitrix\Disk\Internal\Service\HtmlViewer\PublicDomainAccessContext;
use Bitrix\Disk\Internal\Service\HtmlViewer\PublicDomainAccessTokenService;
use Bitrix\Disk\Internal\Service\HtmlViewer\PublicDomainUrlResolver;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\User;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Loader;
use Bitrix\Main\Request;
use Bitrix\Main\Response;
use Bitrix\Main\SystemException;

class UnifiedLinkController extends Controller
{
	/** The one action of the link which names no mode of its own and lets the server choose it. */
	private const ACTION_LETTING_THE_SERVER_CHOOSE = 'view';
	private const QUERY_PUBLIC_DOMAIN = 'public_domain';
	private const QUERY_PUBLIC_DOMAIN_RETRY = 'public_domain_retry';
	private const QUERY_ACCESS_TOKEN = 'access_token';

	private RequiredParameter $requiredParameterFilter;
	private UnifiedLinkAccessLevelRouter $accessControlFilter;
	private FileTypeControl $fileTypeFilter;
	private RedirectToCorrectPrefix $redirectToCorrectPrefix;
	private readonly PublicDomainAccessTokenService $publicDomainAccessTokenService;
	private readonly PublicDomainUrlResolver $publicDomainUrlResolver;
	private ?string $publicDomainFallbackUrl = null;
	private bool $publicDomainAccessRejected = false;

	public function __construct(
		?Request $request = null,
		?PublicDomainAccessTokenService $publicDomainAccessTokenService = null,
		?PublicDomainUrlResolver $publicDomainUrlResolver = null,
	)
	{
		$this->requiredParameterFilter = new RequiredParameter('service');
		$this->fileTypeFilter = new FileTypeControl($this);
		$this->accessControlFilter = new UnifiedLinkAccessLevelRouter($this);
		$this->redirectToCorrectPrefix = new RedirectToCorrectPrefix($this);
		$this->publicDomainAccessTokenService = $publicDomainAccessTokenService ?? new PublicDomainAccessTokenService();
		$this->publicDomainUrlResolver =
			$publicDomainUrlResolver
			?? PublicDomainUrlResolver::createFromPortalConfiguration()
		;

		parent::__construct($request);
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = [
			new HttpMethod([HttpMethod::METHOD_GET]),
			$this->redirectToCorrectPrefix,
			$this->fileTypeFilter,
			$this->accessControlFilter,
		];
		if (!$this->isPublicDomainRequest())
		{
			array_unshift($filters, $this->requiredParameterFilter);
		}

		return $filters;
	}

	protected function getDefaultPostFilters(): array
	{
		return [
			$this->requiredParameterFilter,
			$this->redirectToCorrectPrefix,
			$this->fileTypeFilter,
			$this->accessControlFilter,
		];
	}

	public function finalizeResponse(Response $response): void
	{
		if ($response instanceof HttpResponse && $this->hasAccessTokenInRequest())
		{
			$this->protectTokenResponse($response);
		}
	}

	/**
	 * @return ExactParameter[]
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				UnifiedLinkRenderer::class,
				'service',
				function ($className,  string $uniqueCode): ?UnifiedLinkRenderer {
					$baseObject = $this->loadBaseObjectByUniqueCode($uniqueCode);
					if (!$baseObject)
					{
						$this->rejectPublicDomainAccess();

						return null;
					}
					$attachedId = (int)$this->request->get('attachedId');
					$versionId = (int)$this->request->get('versionId');
					// The renderer binds on its own for every caller; bound here as well because the signed
					// context gate and ExternalLinkContext::resolve() answer before the renderer exists.
					$attachedObject = BaseObjectResolver::resolveAttachedObject(
						$baseObject,
						AttachedObject::loadById($attachedId),
					);
					$version = BaseObjectResolver::resolveVersion(
						$baseObject,
						Version::loadById($versionId),
					);
					$externalLink = null;
					$externalLinkContext = $this->request->getQuery(ExternalLinkContext::getParameterName());

					if ($externalLinkContext !== null)
					{
						if (
							!$baseObject instanceof File
							|| !is_string($externalLinkContext)
							|| !$this->areRequestedObjectsBound(
								$attachedId,
								$versionId,
								$attachedObject,
								$version,
							)
						)
						{
							return null;
						}

						$externalLink = ExternalLinkContext::resolve(
							$externalLinkContext,
							$baseObject,
							$attachedId,
							$versionId,
						);
						if ($externalLink === null)
						{
							return null;
						}
					}
					$analytics = $this->request->getQuery('analytics') ?? [];
					$isDeferred = $this->request->get('immediate_load') !== 'Y';

					if ($this->isPublicDomainRequest() && $externalLinkContext === null)
					{
						return $this->createPublicDomainRenderer(
							$baseObject,
							$attachedId,
							$versionId,
							$attachedObject,
							$version,
							$analytics,
							$isDeferred,
						);
					}

					$currentUser = $this->getCurrentUser();

					return new UnifiedLinkRenderer(
						baseObject: $baseObject,
						attachedObject: $attachedObject,
						version: $version,
						analytics: $analytics,
						currentUser: $currentUser,
						deferred: $isDeferred,
						externalLink: $externalLink,
						modeSelection: $this->resolveModeSelection(),
					);
				},
			),
		];
	}

	protected function loadBaseObjectByUniqueCode(string $uniqueCode): ?BaseObject
	{
		return BaseObject::loadByUniqueCode($uniqueCode);
	}

	protected function loadDiskUser(int $userId): ?User
	{
		return User::loadById($userId);
	}

	protected function getSessionUserId(): int
	{
		return (int)$this->getCurrentUser()->getId();
	}

	protected function isEligibleCloudPortal(): bool
	{
		return Loader::includeModule('bitrix24') && !\CBitrix24::isCustomDomain();
	}

	/** Keep the requested mode for explicit actions and addresses carrying an explicit mode choice. */
	private function resolveModeSelection(): ModeSelection
	{
		return (
			$this->runsAnActionNamingItsOwnMode()
			|| $this->request->get(UnifiedLink\UrlGenerator::QUERY_PARAM_EXACT_MODE) !== null
		)
			? ModeSelection::KeepRequested
			: ModeSelection::Auto
		;
	}

	/**
	 * Whether the running action names a mode of its own. Outside a request there is no action to ask, and
	 * then the address alone answers for the mode.
	 */
	private function runsAnActionNamingItsOwnMode(): bool
	{
		try
		{
			$actionName = $this->getCurrentAction()->getName();
		}
		catch (SystemException)
		{
			return false;
		}

		return $actionName !== self::ACTION_LETTING_THE_SERVER_CHOOSE;
	}

	/**
	 * Tells whether the requested ids survived the binding to the file. The signed context was issued for
	 * these very ids, so an attachment or a revision dropped as belonging to another object refuses the
	 * handoff instead of quietly opening the file alone.
	 */
	private function areRequestedObjectsBound(
		int $attachedId,
		int $versionId,
		?AttachedObject $attachedObject,
		?Version $version,
	): bool
	{
		return ($attachedId <= 0 || $attachedObject !== null)
			&& ($versionId <= 0 || $version !== null)
		;
	}

	#[LevelAccess(UnifiedLinkAccessLevel::Read)]
	#[UrlGenerator([new UrlManager(), 'getUnifiedLink'])]
	#[FileTypes(
		TypeFile::IMAGE,
		TypeFile::VIDEO,
		TypeFile::DOCUMENT,
		TypeFile::ARCHIVE,
		TypeFile::SCRIPT,
		TypeFile::UNKNOWN,
		TypeFile::PDF,
		TypeFile::AUDIO,
		TypeFile::KNOWN,
		TypeFile::VECTOR_IMAGE,
		TypeFile::BOARD,
	)]
	public function viewAction(?UnifiedLinkRenderer $service): HttpResponse
	{
		return $this->createResponse($service, UnifiedLinkAccessLevel::Read);
	}

	#[LevelAccess(UnifiedLinkAccessLevel::Edit)]
	#[UrlGenerator([new UrlManager(), 'getUnifiedEditLink'])]
	#[FileTypes(TypeFile::DOCUMENT)]
	#[RedirectToView(
		TypeFile::IMAGE,
		TypeFile::VIDEO,
		TypeFile::ARCHIVE,
		TypeFile::SCRIPT,
		TypeFile::UNKNOWN,
		TypeFile::AUDIO,
		TypeFile::KNOWN,
		TypeFile::VECTOR_IMAGE,
		TypeFile::BOARD,
	)]
	public function editAction(?UnifiedLinkRenderer $service): HttpResponse
	{
		return $this->createResponse($service, UnifiedLinkAccessLevel::Edit);
	}

	/**
	 * @param UnifiedLinkRenderer|null $service
	 * @param UnifiedLinkAccessLevel $accessLevel
	 * @return HttpResponse
	 * @throws ArgumentTypeException
	 */
	private function createResponse(?UnifiedLinkRenderer $service, UnifiedLinkAccessLevel $accessLevel): HttpResponse
	{
		if ($this->publicDomainFallbackUrl !== null)
		{
			return (new HttpResponse())
				->setStatus(302)
				->addHeader('Location', $this->publicDomainFallbackUrl)
			;
		}

		if ($this->publicDomainAccessRejected)
		{
			return (new HttpResponse())
				->setStatus(403)
				->setContent(UnifiedLinkRenderer::renderAccessDeniedPage())
			;
		}

		if ($service === null)
		{
			return (new HttpResponse())
				->setStatus(404)
				->setContent(UnifiedLinkRenderer::renderAccessDeniedPage())
			;
		}

		$publicDomainRedirect = $this->createCanonicalToPublicRedirect($service);
		if ($publicDomainRedirect !== null)
		{
			return $publicDomainRedirect;
		}

		$result = $service->render($accessLevel);
		$redirectUrl = $result->getRedirectUrl();

		if(is_string($redirectUrl))
		{
			return $this->redirectTo($redirectUrl);
		}

		$component = $result->getComponent();
		if ($component !== null)
		{
			return $this->renderComponent($component, withSiteTemplate: false);
		}

		$response = (new HttpResponse())
			->setStatus($result->getStatus())
			->setContent($result->getContent())
		;

		foreach ($result->getHeaders() as $name => $value)
		{
			$response->addHeader($name, $value);
		}

		return $response;
	}

	private function createPublicDomainRenderer(
		BaseObject $baseObject,
		int $attachedId,
		int $versionId,
		?AttachedObject $attachedObject,
		?Version $version,
		array $analytics,
		bool $isDeferred,
	): ?UnifiedLinkRenderer
	{
		$token = $this->request->getQuery(self::QUERY_ACCESS_TOKEN);
		$context = is_string($token) ? $this->publicDomainAccessTokenService->decodeToken($token) : null;

		if (
			$context === null
			|| !$this->areRequestedObjectsBound($attachedId, $versionId, $attachedObject, $version)
			|| !$this->contextMatchesSource($context, $baseObject, $attachedObject, $version)
			|| !$this->isActiveUser($context->getUserId())
			|| !$this->isHtmlViewerTarget($baseObject, $attachedObject, $version)
		)
		{
			$this->rejectPublicDomainAccess($baseObject, $attachedId, $versionId);

			return null;
		}

		return new UnifiedLinkRenderer(
			baseObject: $baseObject,
			attachedObject: $attachedObject,
			version: $version,
			analytics: $analytics,
			currentUser: null,
			deferred: $isDeferred,
			publicDomainAccessContext: $context,
		);
	}

	private function createCanonicalToPublicRedirect(UnifiedLinkRenderer $renderer): ?HttpResponse
	{
		if (
			$this->isPublicDomainRequest()
			|| $renderer->getPublicDomainAccessContext() !== null
			|| $this->request->getQuery(ExternalLinkContext::getParameterName()) !== null
			|| !$this->isEligibleCloudPortal()
		)
		{
			return null;
		}

		$userId = $this->getSessionUserId();
		$context = $this->createContextForRenderer($renderer, $userId);
		if (
			$context === null
			|| !$this->isActiveUser($userId)
			|| $renderer->getAccessLevel() === UnifiedLinkAccessLevel::Denied
		)
		{
			return null;
		}

		$query = [
			self::QUERY_PUBLIC_DOMAIN => 'Y',
			self::QUERY_ACCESS_TOKEN => $this->publicDomainAccessTokenService->createToken($context),
		];
		if ($this->hasRetryMarker())
		{
			$query[self::QUERY_PUBLIC_DOMAIN_RETRY] = 'Y';
		}

		$relativeUrl = $this->buildRelativeUrl($renderer->resolveBaseObject(), $query);
		$publicUrl = $this->publicDomainUrlResolver->getPublicUrl($relativeUrl);
		if ($publicUrl === null)
		{
			return null;
		}

		$response = (new HttpResponse())
			->setStatus(302)
			->addHeader('Location', $publicUrl)
		;

		return $this->protectTokenResponse($response);
	}

	private function createContextForRenderer(
		UnifiedLinkRenderer $renderer,
		int $userId,
	): ?PublicDomainAccessContext
	{
		if ($userId <= 0)
		{
			return null;
		}

		$baseObject = $renderer->resolveBaseObject();
		$attachedObject = $renderer->resolveAttachedObject();
		$version = $renderer->resolveVersion();
		if (!$this->isHtmlViewerTarget($baseObject, $attachedObject, $version))
		{
			return null;
		}

		$source = $this->resolveSource($baseObject, $attachedObject, $version);
		if ($source === null)
		{
			return null;
		}

		return new PublicDomainAccessContext(
			$userId,
			(int)$baseObject->getRealObjectId(),
			$source['type'],
			$source['id'],
		);
	}

	private function contextMatchesSource(
		PublicDomainAccessContext $context,
		BaseObject $baseObject,
		?AttachedObject $attachedObject,
		?Version $version,
	): bool
	{
		$source = $this->resolveSource($baseObject, $attachedObject, $version);

		return $source !== null
			&& $context->getObjectId() === (int)$baseObject->getRealObjectId()
			&& $context->getSourceType() === $source['type']
			&& $context->getSourceId() === $source['id']
		;
	}

	/**
	 * @return array{type: string, id: int}|null
	 */
	private function resolveSource(
		BaseObject $baseObject,
		?AttachedObject $attachedObject,
		?Version $version,
	): ?array
	{
		if (!$baseObject instanceof File || ($attachedObject !== null && $version !== null))
		{
			return null;
		}

		if ($attachedObject !== null)
		{
			return [
				'type' => PublicDomainAccessContext::SOURCE_TYPE_ATTACHED_OBJECT,
				'id' => (int)$attachedObject->getId(),
			];
		}

		if ($version !== null)
		{
			return [
				'type' => PublicDomainAccessContext::SOURCE_TYPE_VERSION,
				'id' => (int)$version->getId(),
			];
		}

		return [
			'type' => PublicDomainAccessContext::SOURCE_TYPE_FILE,
			'id' => (int)$baseObject->getId(),
		];
	}

	private function isHtmlViewerTarget(
		BaseObject $baseObject,
		?AttachedObject $attachedObject,
		?Version $version,
	): bool
	{
		if (!$baseObject instanceof File)
		{
			return false;
		}

		$revision = $version ?? $attachedObject?->getVersion();
		if ($revision !== null)
		{
			return Configuration::isEnabledHtmlViewer()
				&& HtmlViewerPolicy::isViewableExtension($revision->getExtension())
			;
		}

		return HtmlViewerPolicy::isViewable($baseObject);
	}

	private function isActiveUser(int $userId): bool
	{
		$user = $userId > 0 ? $this->loadDiskUser($userId) : null;

		return $user instanceof User && $user->getActive() === 'Y';
	}

	private function rejectPublicDomainAccess(
		?BaseObject $baseObject = null,
		int $attachedId = 0,
		int $versionId = 0,
	): void
	{
		$this->publicDomainAccessRejected = $this->isPublicDomainRequest();
		if (!$this->publicDomainAccessRejected || $this->hasRetryMarker() || $baseObject === null)
		{
			return;
		}

		$relativeUrl = $this->buildRelativeUrl(
			$baseObject,
			[self::QUERY_PUBLIC_DOMAIN_RETRY => 'Y'],
			$attachedId,
			$versionId,
		);
		$this->publicDomainFallbackUrl = $this->publicDomainUrlResolver->getCanonicalUrl($relativeUrl);
	}

	private function buildRelativeUrl(
		BaseObject $baseObject,
		array $additionalQueryParams,
		?int $attachedId = null,
		?int $versionId = null,
	): string
	{
		return (new UrlManager())->getUnifiedLink($baseObject, [
			'attachedId' => $attachedId ?? $this->positiveQueryId('attachedId'),
			'versionId' => $versionId ?? $this->positiveQueryId('versionId'),
			'additionalQueryParams' => $additionalQueryParams,
		]);
	}

	private function positiveQueryId(string $name): ?int
	{
		$value = (int)$this->request->getQuery($name);

		return $value > 0 ? $value : null;
	}

	private function isPublicDomainRequest(): bool
	{
		return $this->request->getQuery(self::QUERY_PUBLIC_DOMAIN) === 'Y';
	}

	private function hasRetryMarker(): bool
	{
		return $this->request->getQuery(self::QUERY_PUBLIC_DOMAIN_RETRY) === 'Y';
	}

	private function hasAccessTokenInRequest(): bool
	{
		return is_string($this->request->getQuery(self::QUERY_ACCESS_TOKEN));
	}

	private function protectTokenResponse(HttpResponse $response): HttpResponse
	{
		$response->addHeader('Cache-Control', 'private, no-store');
		$response->addHeader('Referrer-Policy', 'no-referrer');

		return $response;
	}
}
