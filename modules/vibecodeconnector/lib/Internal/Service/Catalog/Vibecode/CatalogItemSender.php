<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Vibecode;

use Bitrix\Main\Application;
use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Embedding\EmbeddingUrl;
use Bitrix\Vibecodeconnector\Internal\Exception\RegistrationFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

final class CatalogItemSender extends BaseSender
{
	private const ACTION_ISSUE_EMBEDDING_URL = 'issueItemEmbeddingUrl';

	private string $currentEndpointUrl = '';

	private array $endpointUrlCache = [];

	public function __construct(
		private readonly BaseEndpointProvider $baseEndpoints = new BaseEndpointProvider(),
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly NetworkService $networkService = new NetworkService(),
	) {
		parent::__construct();
	}

	public function issueEmbeddingUrl(CatalogItem $item, int $userId): EmbeddingUrl
	{
		$this->currentEndpointUrl = $this->resolveEndpointUrl($item->getPairingIss());

		$params = $this->baseParams($userId) + [
			'catalog_item_id' => $item->getId(),
			'type' => $item->getType()->value,
			'handler_url' => $item->getEditUrl() ?? $item->getViewUrl(),
			'edit_url' => $item->getEditUrl(),
			'view_url' => $item->getViewUrl(),
			'chat_id' => $item->getChatId(),
		];

		return $this->dispatch(self::ACTION_ISSUE_EMBEDDING_URL, $params);
	}

	protected function getServiceUrl(): string
	{
		return (new EndpointResolver($this->currentEndpointUrl))->microservice();
	}

	private function dispatch(string $action, array $params): EmbeddingUrl
	{
		$result = $this->performRequest($action, $params);
		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$first = $errors[0] ?? null;

			throw new RegistrationFailedException(
				'Vibecode microservice request failed: ' . implode('; ', $result->getErrorMessages()),
				$first?->getCode() !== null ? (string)$first->getCode() : null,
			);
		}

		$data = $result->getData();
		$url = (string)($data['url'] ?? '');
		$expiresAt = (int)($data['expires_at'] ?? 0);

		if ($url === '' || $expiresAt <= 0)
		{
			throw new RegistrationFailedException(
				'Vibecode microservice response missing url or expires_at',
				'INVALID_RESPONSE',
			);
		}

		return new EmbeddingUrl($url, $expiresAt);
	}

	private function baseParams(int $userId): array
	{
		$params = [
			'user_id' => $userId,
			'domain' => $this->resolveDomain(),
		];

		$networkUserId = $this->networkService->getUserNetworkId($userId);
		if ($networkUserId !== null)
		{
			$params['network_user_id'] = $networkUserId;
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

	private function resolveEndpointUrl(?string $pairingIss): string
	{
		$cacheKey = $pairingIss ?? '';
		if (isset($this->endpointUrlCache[$cacheKey]))
		{
			return $this->endpointUrlCache[$cacheKey];
		}

		$resolved = $this->baseEndpoints->getBaseUrl();
		if ($pairingIss !== null && $pairingIss !== '')
		{
			$pairing = $this->pairingRepository->findByIss($pairingIss);
			if ($pairing !== null && $pairing->endpointUrl !== '')
			{
				$resolved = $pairing->endpointUrl;
			}
		}

		return $this->endpointUrlCache[$cacheKey] = $resolved;
	}
}
