<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Diagnostic;

use Bitrix\Main\Application;
use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Vibecodeconnector\Internal\Entity\Embedding\EmbeddingUrl;
use Bitrix\Vibecodeconnector\Internal\Exception\RegistrationFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

final class StatusUrlSender extends BaseSender
{
	private const ACTION_STATUS = 'issueStatusUrl';

	public function __construct(
		private readonly EndpointResolver $endpoints,
		private readonly NetworkService $networkService = new NetworkService(),
	) {
		parent::__construct();
	}

	public function issueStatusUrl(int $userId, ?string $baseUrlOverride = null): EmbeddingUrl
	{
		$params = $this->baseParams($userId);
		if ($baseUrlOverride !== null && $baseUrlOverride !== '')
		{
			$params['base_url_override'] = $baseUrlOverride;
		}

		$result = $this->performRequest(self::ACTION_STATUS, $params);
		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$first = $errors[0] ?? null;

			throw new RegistrationFailedException(
				'Status URL request failed: ' . implode('; ', $result->getErrorMessages()),
				$first?->getCode() !== null ? (string)$first->getCode() : null,
			);
		}

		$data = $result->getData();
		$url = (string)($data['url'] ?? '');
		$expiresAt = (int)($data['expires_at'] ?? 0);

		if ($url === '' || $expiresAt <= 0)
		{
			throw new RegistrationFailedException(
				'Status URL response missing url or expires_at',
				'INVALID_RESPONSE',
			);
		}

		return new EmbeddingUrl($url, $expiresAt);
	}

	protected function getServiceUrl(): string
	{
		return $this->endpoints->microservice();
	}

	private function baseParams(int $userId): array
	{
		$params = [
			'user_id' => $userId,
			'domain' => $this->resolveDomain(),
		];

		if ($this->networkService->isCloudPortal())
		{
			$params['network_user_id'] = $this->networkService->getUserNetworkId($userId);
			$params['portal_network_id'] = $this->networkService->getPortalNetworkId();
		}

		return $params;
	}

	private function resolveDomain(): string
	{
		if (defined('BX24_HOST_NAME'))
		{
			return (string)BX24_HOST_NAME;
		}

		return (string)Application::getInstance()->getContext()->getServer()->getServerName();
	}
}
