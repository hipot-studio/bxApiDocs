<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Cabinet;

use Bitrix\Main\Web\Uri;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\OpenApp\OpenAppPayload;
use Bitrix\Vibecodeconnector\Internal\Exception\CabinetTransitionException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\MainPortalFieldsProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\PortalUserProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Transition\TransitionFieldsBuilder;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Transition\TransitionSurface;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\VibecodeUrlProvider;

final class CabinetPayloadBuilder
{
	private const NETWORK_STATE_USER = 'user';
	private const NETWORK_STATE_ABSENT = 'absent';
	private const DEFAULT_TARGET_PATH = '/dashboard';

	private readonly TransitionFieldsBuilder $fieldsBuilder;
	private readonly VibecodeUrlProvider $urlProvider;

	public function __construct(
		BaseEndpointProvider $baseEndpointProvider = new BaseEndpointProvider(),
		PortalUserProvider $portalUserProvider = new PortalUserProvider(),
		MainPortalFieldsProvider $portalFieldsProvider = new MainPortalFieldsProvider(),
		private readonly NetworkService $networkService = new NetworkService(),
	) {
		$this->urlProvider = new VibecodeUrlProvider($networkService, $baseEndpointProvider);
		$this->fieldsBuilder = new TransitionFieldsBuilder(
			$portalFieldsProvider,
			$networkService,
			$portalUserProvider,
		);
	}

	public function build(int $userId, ?string $target = null): OpenAppPayload
	{
		$baseUrl = trim($this->urlProvider->getEndpointUrl());
		if ($baseUrl === '')
		{
			throw new CabinetTransitionException(
				'Vibecode endpoint is unavailable',
				CabinetTransitionException::CODE_ENDPOINT_UNAVAILABLE,
			);
		}

		$surfaceFields = [
			'BX_VIBE_AUD' => $this->resolveAudience($baseUrl),
		];

		if (!$this->networkService->isCloudPortal())
		{
			$surfaceFields['BX_VIBE_NETWORK_STATE'] = $this->resolveNetworkState($userId);
		}

		$requestedTarget = $target === null ? '' : trim($target);
		if ($requestedTarget !== '' && $requestedTarget !== self::DEFAULT_TARGET_PATH)
		{
			$surfaceFields['BX_VIBE_TARGET'] = $this->assertRelativePath($requestedTarget);
		}

		return new OpenAppPayload(
			(new EndpointResolver($baseUrl))->microservice() . '/open-cabinet/',
			$this->fieldsBuilder->build(TransitionSurface::Cabinet, $userId, $surfaceFields),
		);
	}

	private function resolveAudience(string $baseUrl): string
	{
		$uri = new Uri($baseUrl);
		$scheme = $uri->getScheme();
		$host = $uri->getHost();
		if (($scheme !== 'http' && $scheme !== 'https') || $host === '')
		{
			throw new CabinetTransitionException(
				'Vibecode endpoint is not an absolute http(s) address',
				CabinetTransitionException::CODE_ENDPOINT_UNAVAILABLE,
			);
		}

		$port = $uri->getPort();
		$isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

		return $scheme . '://' . $host . ($port === null || $isDefaultPort ? '' : ':' . $port);
	}

	private function resolveNetworkState(int $userId): string
	{
		if (
			$this->networkService->getPortalNetworkId() !== null
			&& $this->networkService->getUserNetworkId($userId) !== null
		)
		{
			return self::NETWORK_STATE_USER;
		}

		return self::NETWORK_STATE_ABSENT;
	}

	private function assertRelativePath(string $target): string
	{
		if (
			!str_starts_with($target, '/')
			|| str_starts_with($target, '//')
			|| preg_match('#[\\\\|\x00-\x20\x7F]#', $target) === 1
		)
		{
			throw new CabinetTransitionException(
				'Cabinet target is not a relative path',
				CabinetTransitionException::CODE_TARGET_INVALID,
			);
		}

		return $target;
	}
}
