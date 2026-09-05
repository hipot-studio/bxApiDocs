<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\FileHandlerOperationResult;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\HtmlRenderableFileHandlerFactory;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use LogicException;

class UnifiedLinkFileRenderer
{
	private UnifiedLinkAccessService $unifiedLinkAccessService;
	private HtmlRenderableFileHandlerFactory $fileHandlerFactory;
	private ExternalLinkProvider $externalLinkProvider;
	private ?CurrentUser $currentUser;
	private ?UnifiedLinkAccessLevel $accessLevel = null;
	private ?bool $shouldRenderExternal = null;
	private bool $isStoredExternalLinkResolved = false;
	private ?ExternalLink $storedExternalLink = null;

	public function __construct(
		private readonly File $file,
		private readonly ?AttachedObject $attachedObject = null,
		private readonly ?Version $version = null,
		private readonly array $analytics = [],
		?CurrentUser $currentUser = null,
		private readonly bool $deferred = false,
		private readonly ?ExternalLink $externalLink = null,
	) {
		if ($currentUser instanceof CurrentUser && (int)$currentUser->getId() === 0)
		{
			$currentUser = null;
		}

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->fileHandlerFactory = $serviceLocator->get(HtmlRenderableFileHandlerFactory::class);
		$this->externalLinkProvider = $serviceLocator->get(ExternalLinkProvider::class);
		$this->currentUser = $currentUser;
	}

	public function getAccessLevel(): UnifiedLinkAccessLevel
	{
		if ($this->accessLevel === null)
		{
			$file = $this->resolveFile();
			$this->accessLevel = $this->unifiedLinkAccessService->check(
				$file,
				$this->attachedObject,
				externalLink: $this->externalLink,
				version: $this->version,
			);
		}

		return $this->accessLevel;
	}

	public function shouldRenderExternal(): bool
	{
		return $this->shouldRenderExternal ??= (function (): bool {
			if ($this->getAccessLevel() !== UnifiedLinkAccessLevel::Denied)
			{
				return false;
			}

			$externalLink = $this->resolveExternalLink();

			return $externalLink instanceof ExternalLink && $externalLink->hasPassword();
		})();
	}

	public function resolveFile(): File
	{
		return FileResolver::resolve($this->file, $this->version);
	}

	/**
	 * The revision the link addresses, which is one of the addressed file or none: a versionId naming
	 * a revision of another object is answered for by neither the access check nor the handler, so the
	 * link opens the file it names.
	 */
	public function resolveVersion(): ?Version
	{
		return FileResolver::resolveVersion($this->file, $this->version);
	}

	public function render(?UnifiedLinkAccessLevel $accessLevel = null): RenderResult
	{
		$accessLevel = $this->getAccessLevelForRender($accessLevel);
		$shouldRenderExternal = $this->shouldRenderExternal();

		if ($accessLevel === UnifiedLinkAccessLevel::Denied && !$shouldRenderExternal)
		{
			return new RenderResult(self::renderAccessDeniedPage(), 403);
		}

		$fileHandler = $this->fileHandlerFactory->createHandler(
			file: $this->file,
			attachedObject: $this->attachedObject,
			version: $this->resolveVersion(),
			analytics: $this->analytics,
			currentUser: $this->currentUser,
			deferred: $this->deferred,
			externalLink: $this->resolveExternalLinkForHandler(),
		);

		$result = match ($accessLevel)
		{
			UnifiedLinkAccessLevel::Edit => $fileHandler->edit(),
			default => $fileHandler->view(),
		};

		if (!$result->isSuccess())
		{
			$content = $this->renderServerErrorPage($result);

			return new RenderResult($content, 500);
		}

		return new RenderResult(
			content: $result->getValue(),
			status: 200,
			redirectUrl: $result->getRedirectUrl(),
			component: $result->getComponent(),
			headers: $result->getHeaders(),
		);
	}

	/**
	 * A link saved before the signed context carries no external link, so it is taken from the object itself:
	 * without it an anonymous visitor loses both the public page and the password gate.
	 */
	protected function resolveExternalLink(): ?ExternalLink
	{
		if ($this->externalLink instanceof ExternalLink)
		{
			return $this->externalLink;
		}

		if (!$this->isStoredExternalLinkResolved)
		{
			$this->isStoredExternalLinkResolved = true;
			$this->storedExternalLink = $this->externalLinkProvider->getForUnifiedLinkAccessCheck(
				$this->resolveFile()->getId(),
			);
		}

		return $this->storedExternalLink;
	}

	protected function resolveExternalLinkForHandler(): ?ExternalLink
	{
		if ($this->externalLink instanceof ExternalLink)
		{
			return $this->externalLink;
		}

		// An authorized user opens the document itself; the public page is only for a guest or a password gate.
		if ($this->currentUser instanceof CurrentUser && !$this->shouldRenderExternal())
		{
			return null;
		}

		return $this->resolveExternalLink();
	}

	public static function renderAccessDeniedPage(): string
	{
		return StandalonePageRenderer::render('bitrix:disk.error.page', 'standalone');
	}

	private function renderServerErrorPage(FileHandlerOperationResult $result): string
	{
		if ($result->isSuccess())
		{
			throw new LogicException('Cannot get server error response from success result');
		}

		return 'Server error occurred. Please try again later.';
	}

	private function getAccessLevelForRender(?UnifiedLinkAccessLevel $accessLevel): UnifiedLinkAccessLevel
	{
		$file = $this->resolveFile();
		$fileAccessLevel = $this->getAccessLevel();

		if ((int)$file->getTypeFile() === TypeFile::BOARD)
		{
			return $fileAccessLevel;
		}

		return $accessLevel ?? $fileAccessLevel;
	}
}
