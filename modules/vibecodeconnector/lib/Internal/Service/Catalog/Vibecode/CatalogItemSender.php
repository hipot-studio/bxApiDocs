<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Vibecode;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Sharing\CatalogShareState;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Embedding\EmbeddingUrl;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogSyncFailedException;
use Bitrix\Vibecodeconnector\Internal\Exception\RegistrationFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon\IconUpdateIntent;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Sharing\CatalogSharingResponseMapper;

class CatalogItemSender extends BaseSender implements CatalogItemUpdateSender
{
	private const ACTION_ISSUE_EMBEDDING_URL = 'issueItemEmbeddingUrl';
	private const ACTION_UPDATE_CATALOG_ITEM = 'updateCatalogItem';
	private const ACTION_GET_SHARE = 'catalog.share.get';
	private const ACTION_SET_SHARE = 'catalog.share.set';
	private const ACTION_GET_LINK = 'catalog.link.get';
	private const ACTION_SET_LINK = 'catalog.link.set';

	private string $currentEndpointUrl = '';

	private array $endpointUrlCache = [];

	public function __construct(
		private readonly BaseEndpointProvider $baseEndpoints = new BaseEndpointProvider(),
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
		private readonly NetworkService $networkService = new NetworkService(),
		private readonly CatalogSharingResponseMapper $sharingResponseMapper = new CatalogSharingResponseMapper(),
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

	public function updateItem(
		CatalogItem $item,
		int $userId,
		IconUpdateIntent $iconIntent = IconUpdateIntent::Keep,
		?array $preparedIcon = null,
	): void
	{
		$this->currentEndpointUrl = $this->resolveEndpointUrl($item->getPairingIss());

		$params = $this->baseParams($userId) + [
			'catalog_item_id' => $item->getId(),
			'title' => $item->getTitle(),
			'description' => $item->getDescription(),
		] + $this->iconParams($iconIntent, $preparedIcon);

		$result = $this->performRequest(self::ACTION_UPDATE_CATALOG_ITEM, $params);
		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$first = $errors[0] ?? null;

			throw new CatalogSyncFailedException(
				'Vibecode updateCatalogItem request failed: ' . implode('; ', $result->getErrorMessages()),
				$first?->getCode() !== null ? (string)$first->getCode() : null,
			);
		}

		$confirmedItemId = $result->getData()['catalog_item_id'] ?? null;
		if ((int)$confirmedItemId !== (int)$item->getId())
		{
			throw new CatalogSyncFailedException(
				'Vibecode updateCatalogItem response does not confirm the item',
				'UNCONFIRMED_RESPONSE',
			);
		}
	}

	/**
	 * @param array{content: string, format: string}|null $preparedIcon
	 * @return array<string, string>
	 */
	private function iconParams(IconUpdateIntent $iconIntent, ?array $preparedIcon): array
	{
		if ($iconIntent === IconUpdateIntent::Keep)
		{
			return [];
		}

		if ($iconIntent === IconUpdateIntent::Delete)
		{
			return ['icon_action' => $iconIntent->value];
		}

		if ($preparedIcon === null)
		{
			throw new CatalogSyncFailedException(
				'Prepared icon content is missing',
				'ICON_NOT_READABLE',
			);
		}

		return [
			'icon_action' => $iconIntent->value,
			'icon_content' => base64_encode($preparedIcon['content']),
			'icon_format' => $preparedIcon['format'],
		];
	}

	public function getShare(CatalogItem $item): Result
	{
		$this->selectEndpoint($item);

		return $this->sharingResponseMapper->mapShare($this->request(
			self::ACTION_GET_SHARE,
			[
				'b24_catalog_item_id' => (int)$item->getId(),
				'department_format' => CatalogShareState::DEPARTMENT_FORMAT_ACCESS_CODE,
			],
		));
	}

	public function getLink(CatalogItem $item): Result
	{
		$this->selectEndpoint($item);

		return $this->sharingResponseMapper->mapLink($this->request(
			self::ACTION_GET_LINK,
			['b24_catalog_item_id' => (int)$item->getId()],
		));
	}

	/**
	 * @param list<array{id: string, name: string}> $users
	 * @param list<array{id: string, name: string}> $departments
	 */
	public function setShare(
		CatalogItem $item,
		int $actingUserId,
		string $audience,
		array $users,
		array $departments,
	): Result {
		$this->selectEndpoint($item);

		$params = [
			'b24_catalog_item_id' => (int)$item->getId(),
			'acting_user_id' => (string)$actingUserId,
			'audience' => $audience,
			'users' => $users,
			'departments' => $departments,
			'department_format' => CatalogShareState::DEPARTMENT_FORMAT_ACCESS_CODE,
		];

		return $this->sharingResponseMapper->mapShare($this->request(
			self::ACTION_SET_SHARE,
			$params,
		));
	}

	public function setLink(
		CatalogItem $item,
		int $actingUserId,
		bool $enabled,
		?string $expiresAt,
		?bool $requireB24Auth,
	): Result {
		$this->selectEndpoint($item);

		$params = [
			'b24_catalog_item_id' => (int)$item->getId(),
			'acting_user_id' => (string)$actingUserId,
			'enabled' => $enabled,
		];
		if ($enabled)
		{
			$params['expires_at'] = $expiresAt;
			$params['require_b24_auth'] = $requireB24Auth;
		}

		return $this->sharingResponseMapper->mapLink($this->request(
			self::ACTION_SET_LINK,
			$params,
		));
	}

	public function getHttpClientParameters(): array
	{
		return array_replace(parent::getHttpClientParameters(), [
			'disableSslVerification' => false,
		]);
	}

	protected function createAnswerForJsonResponse($queryResult, $response, $errors, $status): Result
	{
		$result = parent::createAnswerForJsonResponse($queryResult, $response, $errors, $status);
		if (!$result->isSuccess() || !is_string($response) || $response === '')
		{
			return $result;
		}

		try
		{
			$payload = Json::decode($response);
		}
		catch (\Throwable)
		{
			return (new Result())->addError(new Error('Malformed Vibecode response', 'PROTOCOL_ERROR'));
		}

		if (!is_array($payload) || ($payload['status'] ?? null) !== 'success')
		{
			return (new Result())->addError(new Error('Vibecode response is not a success envelope', 'PROTOCOL_ERROR'));
		}

		return $result;
	}

	protected function getServiceUrl(): string
	{
		return (new EndpointResolver($this->currentEndpointUrl))->microservice();
	}

	private function dispatch(string $action, array $params): EmbeddingUrl
	{
		$result = $this->request($action, $params);
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

	protected function request(string $action, array $params): Result
	{
		return $this->performRequest($action, $params);
	}

	private function selectEndpoint(CatalogItem $item): void
	{
		$this->currentEndpointUrl = $this->resolveEndpointUrl($item->getPairingIss());
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
