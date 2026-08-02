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

	public function __construct(
		private readonly File $file,
		private readonly ?AttachedObject $attachedObject = null,
		private readonly ?Version $version = null,
		private readonly array $analytics = [],
		?CurrentUser $currentUser = null,
		private readonly bool $deferred = false,
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
			$this->accessLevel = $this->unifiedLinkAccessService->check($file, $this->attachedObject);
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

			$file = $this->resolveFile();
			$externalLink = $this->externalLinkProvider->getForUnifiedLinkAccessCheck($file->getId());

			return $externalLink instanceof ExternalLink && $externalLink->hasPassword();
		})();
	}

	public function resolveFile(): File
	{
		return FileResolver::resolve($this->file, $this->version);
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
			version: $this->version,
			analytics: $this->analytics,
			currentUser: $this->currentUser,
			forceExternal: $shouldRenderExternal,
			deferred: $this->deferred,
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
		);
	}

	public static function renderAccessDeniedPage(): string
	{
		return $GLOBALS['APPLICATION']->includeComponent(
			'bitrix:ui.sidepanel.wrapper',
			'',
			[
				'RETURN_CONTENT' => true,
				'POPUP_COMPONENT_NAME' => 'bitrix:disk.error.page',
				'POPUP_COMPONENT_PARAMS' => [
				],
				'PLAIN_VIEW' => false,
				'IFRAME_MODE' => true,
				'PREVENT_LOADING_WITHOUT_IFRAME' => false,
				'USE_PADDING' => true,
			],
		);
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
