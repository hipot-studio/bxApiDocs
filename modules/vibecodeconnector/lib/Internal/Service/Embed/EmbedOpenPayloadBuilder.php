<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Embed;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;
use Bitrix\Vibecodeconnector\Internal\Dto\Embed\SignedFormPayload;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\MainPortalFieldsProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\CloudEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserAttributesResolver;

final class EmbedOpenPayloadBuilder
{
	public function __construct(
		private readonly BaseEndpointProvider $baseEndpointProvider = new BaseEndpointProvider(),
		private readonly CloudEndpointProvider $cloudEndpointProvider = new CloudEndpointProvider(),
		private readonly MainPortalFieldsProvider $portalFieldsProvider = new MainPortalFieldsProvider(),
		private readonly NetworkService $networkService = new NetworkService(),
		private readonly UserAttributesResolver $userAttributes = new UserAttributesResolver(),
	) {
	}

	public function build(EmbedView $view, int $userId): SignedFormPayload
	{
		$fields = [
			'BX_TYPE' => $this->portalFieldsProvider->getPortalType(),
			'BX_LICENCE' => $this->portalFieldsProvider->getLicenseCode(),
			'BX_REGION' => $this->portalFieldsProvider->getLicenseRegion(),
			'SERVER_NAME' => $this->portalFieldsProvider->getServerName(),
			'BITRIX_USER_ID' => (string)$userId,
			'USER_ROLE' => $this->resolveRole($userId),
			'VIEW' => $view->value,
			'LOCALE' => $this->portalFieldsProvider->getLocale(),
			'TS' => (string)time(),
			'NONCE' => Random::getString(32, true),
		];
		$fields['BX_HASH'] = $this->portalFieldsProvider->sign($fields);

		return new SignedFormPayload(
			(new EndpointResolver($this->resolveEndpointUrl()))->embedOpen(),
			$fields,
			$this->resolveFrameTitle($view),
			clipboardWrite: true,
		);
	}

	private function resolveFrameTitle(EmbedView $view): string
	{
		$code = match ($view)
		{
			EmbedView::CreateHub => 'VIBECODECONNECTOR_EMBED_FRAME_TITLE_CREATE',
			EmbedView::ApplicationCreate => 'VIBECODECONNECTOR_EMBED_FRAME_TITLE_APPLICATION',
			EmbedView::AgentCreate => 'VIBECODECONNECTOR_EMBED_FRAME_TITLE_AGENT',
			EmbedView::CoworkAdmin => 'VIBECODECONNECTOR_EMBED_FRAME_TITLE_COWORK_ADMIN',
		};

		return (string)Loc::getMessage($code);
	}

	private function resolveRole(int $userId): string
	{
		return $this->userAttributes->resolveForUser($userId)['isAdmin'] ? 'admin' : 'member';
	}

	private function resolveEndpointUrl(): string
	{
		return $this->networkService->isCloudPortal()
			? trim($this->cloudEndpointProvider->getCloudUrl())
			: trim($this->baseEndpointProvider->getBaseUrl());
	}
}
