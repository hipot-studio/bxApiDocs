<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp;

use Bitrix\Main\Engine\Response\Redirect;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Web\UserAgent\Browser;
use Bitrix\Rest\Public\Provider\EmbeddingProvider;
use Bitrix\Vibecodeconnector\Infrastructure\Dto\EmbeddingOpenContext;
use Bitrix\Vibecodeconnector\Infrastructure\Dto\OpenAppLayoutDto;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\OpenApp\OpenAppPayload;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Exception\OpenAppException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusPolicy;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Launch\LaunchService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppPayloadBuilder;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatOnDemandService;

final class OpenAppLayoutService
{
	private const ERROR_DISABLED = 'OPEN_APP_DISABLED';
	private const ERROR_NOT_FOUND = 'ITEM_NOT_FOUND';
	private const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	private const ERROR_BUILD_FAILED = 'OPEN_APP_BUILD_FAILED';
	private const ERROR_FEATURE_NOT_AVAILABLE = 'FEATURE_NOT_AVAILABLE_ON_CURRENT_PLAN';
	private const ERROR_CATALOG_NOT_AVAILABLE = 'CATALOG_NOT_AVAILABLE';
	private const MOBILE_APP_BROWSER = 'Bitrix24.Mobile';

	public function __construct(
		private readonly OpenAppSettings $settings = new OpenAppSettings(),
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly OpenAppPayloadBuilder $payloadBuilder = new OpenAppPayloadBuilder(),
		private readonly OpenAppLayoutRenderer $renderer = new OpenAppLayoutRenderer(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly VibePlusPolicy $vibePlusPolicy = new VibePlusPolicy(),
		private readonly AppChatOnDemandService $appChatOnDemandService = new AppChatOnDemandService(),
		private readonly LaunchService $launchService = new LaunchService(),
		private readonly PortalNavigation $portalNavigation = new PortalNavigation(),
		private readonly IconStorageService $iconStorage = new IconStorageService(),
		private readonly ?string $userAgent = null,
	) {
	}

	public function renderLayout(int $catalogItemId, int $userId): OpenAppLayoutDto
	{
		if (!$this->settings->isOpenInIframeEnabled())
		{
			return $this->createErrorLayout(self::ERROR_DISABLED);
		}

		$item = $this->resolveAccessibleItem($catalogItemId, $userId);
		if (is_string($item))
		{
			return $this->createErrorLayout($item);
		}

		$payload = $this->buildPayload($item, $userId);
		if (is_string($payload))
		{
			return $this->createErrorLayout($payload);
		}

		$this->appChatOnDemandService->ensureUserChatBestEffort((int)$item->getId(), $userId);

		$iconFileId = $item->getIconFileId();

		return new OpenAppLayoutDto(
			html: $this->renderer->render($payload),
			title: $payload->appTitle,
			errorCode: '',
			iconUrl: $iconFileId === null ? null : $this->iconStorage->getPublicUrl($iconFileId),
			color: $item->getColor(),
		);
	}

	public function renderPageResponse(int $catalogItemId, int $userId, array $placementFields = []): HttpResponse
	{
		$topLevel = Browser::detect($this->userAgent)->getName() === self::MOBILE_APP_BROWSER;
		$response = $this->renderResponse($catalogItemId, $userId, $topLevel, $placementFields);
		if ($topLevel)
		{
			$response->addHeader('X-Frame-Options', 'DENY');
			$response->addHeader('Content-Security-Policy', "frame-ancestors 'none'");
		}

		return $response;
	}

	private function renderResponse(int $catalogItemId, int $userId, bool $topLevel, array $placementFields): HttpResponse
	{
		$item = $this->resolveAccessibleItem($catalogItemId, $userId);
		if (is_string($item))
		{
			return $this->createHtmlResponse(
				$this->renderer->renderErrorPage(...$this->errorParams($item)),
			);
		}

		$embeddingFields = $this->resolveEmbeddingFields($catalogItemId, $userId, $placementFields);
		if (is_string($embeddingFields))
		{
			return $this->createHtmlResponse(
				$this->renderer->renderErrorPage(...$this->errorParams($embeddingFields)),
			);
		}

		$response = $this->openApp($item, $userId, $topLevel, $embeddingFields);
		if (is_string($response))
		{
			return $this->createHtmlResponse(
				$this->renderer->renderErrorPage(...$this->errorParams($response)),
			);
		}

		if ($this->portalNavigation->isOwn())
		{
			$this->appChatOnDemandService->ensureUserChatBestEffort((int)$item->getId(), $userId);
			$this->recordLaunch($item, $userId);
		}

		return $response;
	}

	private function openApp(CatalogItem $item, int $userId, bool $topLevel, array $embeddingFields): HttpResponse|string
	{
		$signed = $topLevel || $this->settings->isOpenInIframeEnabled();
		if (!$signed || trim((string)$item->getExternalId()) === '')
		{
			$viewUrl = $this->resolveViewUrl($item);
			if ($viewUrl !== null)
			{
				$response = new Redirect($viewUrl);
				$response->addHeader('Cache-Control', 'private, no-store');

				return $response;
			}

			return $signed ? OpenAppException::CODE_EXTERNAL_ID_MISSING : self::ERROR_DISABLED;
		}

		$payload = $this->buildPayload($item, $userId, $embeddingFields);
		if (is_string($payload))
		{
			return $payload;
		}

		return $this->createHtmlResponse(
			$topLevel ? $this->renderer->renderTopLevelPage($payload) : $this->renderer->renderPage($payload),
		);
	}

	public function renderCatalogUnavailablePageResponse(): HttpResponse
	{
		return $this->createHtmlResponse(
			$this->renderer->renderErrorPage(...$this->errorParams(self::ERROR_CATALOG_NOT_AVAILABLE)),
		);
	}

	private function recordLaunch(CatalogItem $item, int $userId): void
	{
		try
		{
			$this->launchService->launchOpenedApp($item, $userId);
		}
		catch (\Throwable $exception)
		{
			\AddMessage2Log(
				"Catalog item {$item->getId()}: the app-page open was not recorded, " . $exception->getMessage(),
				'vibecodeconnector',
			);
		}
	}

	private function createHtmlResponse(string $content): HttpResponse
	{
		$response = new HttpResponse();
		$response->addHeader('Content-Type', 'text/html; charset=UTF-8');
		$response->addHeader('Cache-Control', 'private, no-store');
		$response->setContent($content);

		return $response;
	}

	private function createErrorLayout(string $errorCode): OpenAppLayoutDto
	{
		return new OpenAppLayoutDto(
			html: $this->renderer->renderError(...$this->errorParams($errorCode)),
			title: '',
			errorCode: $errorCode,
			iconUrl: null,
			color: null,
		);
	}

	private function resolveAccessibleItem(int $catalogItemId, int $userId): CatalogItem|string
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null || $item->isDeactivated())
		{
			return self::ERROR_NOT_FOUND;
		}

		if ($item->getType() !== CatalogItemType::Application)
		{
			return self::ERROR_NOT_FOUND;
		}

		if ($userId <= 0)
		{
			return self::ERROR_ACCESS_DENIED;
		}

		if (!$this->itemRepository->isAccessibleToUser(
			$catalogItemId,
			$userId,
			$this->accessCodes->getUserCodesForItems($userId, [$catalogItemId]),
		))
		{
			return self::ERROR_ACCESS_DENIED;
		}

		if ($this->vibePlusPolicy->getAvailability() === false)
		{
			return self::ERROR_FEATURE_NOT_AVAILABLE;
		}

		return $item;
	}

	private function buildPayload(CatalogItem $item, int $userId, array $embeddingFields = []): OpenAppPayload|string
	{
		try
		{
			return $this->payloadBuilder->build($item, $userId, $embeddingFields);
		}
		catch (OpenAppException $exception)
		{
			return $exception->getErrorCode();
		}
		catch (\Throwable)
		{
			return self::ERROR_BUILD_FAILED;
		}
	}

	private function resolveEmbeddingFields(int $catalogItemId, int $userId, array $fields): array|string
	{
		if ($fields === [])
		{
			return [];
		}

		try
		{
			$context = EmbeddingOpenContext::fromFields($fields);
		}
		catch (\InvalidArgumentException)
		{
			return self::ERROR_BUILD_FAILED;
		}

		try
		{
			if (!Loader::includeModule('rest'))
			{
				return self::ERROR_BUILD_FAILED;
			}

			$provider = new EmbeddingProvider();
			$embedding = $provider->getById($context->placementId);
			if (
				$embedding === null
				|| $embedding->moduleId !== 'vibecodeconnector'
				|| $embedding->entityType !== 'catalog_item'
				|| $embedding->entityId !== (string)$catalogItemId
				|| $embedding->placement !== $context->placement
				|| $provider->resolveOpen($embedding, $userId) === null
			)
			{
				return self::ERROR_BUILD_FAILED;
			}
		}
		catch (\Throwable)
		{
			return self::ERROR_BUILD_FAILED;
		}

		return [
			'PLACEMENT' => $embedding->placement,
			'PLACEMENT_ID' => (string)$embedding->id,
			'PLACEMENT_OPTIONS' => $context->placementOptions,
		];
	}

	private function resolveViewUrl(CatalogItem $item): ?string
	{
		$viewUrl = trim((string)$item->getViewUrl());
		if (
			$viewUrl === ''
			|| str_contains($viewUrl, "\r")
			|| str_contains($viewUrl, "\n")
		)
		{
			return null;
		}

		if (str_starts_with($viewUrl, '/'))
		{
			return str_starts_with($viewUrl, '//') ? null : $viewUrl;
		}

		$uri = new Uri($viewUrl);
		if (
			!in_array($uri->getScheme(), ['http', 'https'], true)
			|| $uri->getHost() === ''
		)
		{
			return null;
		}

		return $viewUrl;
	}

	/**
	 * @return array{string, string, string} error code, title and message
	 */
	private function errorParams(string $code): array
	{
		$accessDenied = in_array($code, [self::ERROR_ACCESS_DENIED, self::ERROR_NOT_FOUND], true);

		return [
			$code,
			(string)Loc::getMessage(
				$accessDenied
					? 'VIBECODECONNECTOR_OPEN_APP_ERROR_ACCESS_DENIED_TITLE'
					: 'VIBECODECONNECTOR_OPEN_APP_ERROR_TITLE'
			),
			(string)(
				Loc::getMessage(
					$accessDenied
						? 'VIBECODECONNECTOR_OPEN_APP_ERROR_ACCESS_DENIED_HINT'
						: 'VIBECODECONNECTOR_OPEN_APP_ERROR_' . $code
				)
				?: Loc::getMessage('VIBECODECONNECTOR_OPEN_APP_ERROR_DEFAULT')
			),
		];
	}
}
