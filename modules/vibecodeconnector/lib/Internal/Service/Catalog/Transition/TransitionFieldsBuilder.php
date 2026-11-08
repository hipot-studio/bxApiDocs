<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Transition;

use Bitrix\Main\Security\Random;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\MainPortalFieldsProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\PortalUserProvider;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;

final class TransitionFieldsBuilder
{
	private const NONCE_LENGTH = 32;

	public function __construct(
		private readonly MainPortalFieldsProvider $portalFieldsProvider = new MainPortalFieldsProvider(),
		private readonly NetworkService $networkService = new NetworkService(),
		private readonly PortalUserProvider $portalUserProvider = new PortalUserProvider(),
	) {
	}

	public function build(TransitionSurface $surface, int $userId, array $surfaceFields = []): array
	{
		$fields = [
			'BX_TYPE' => $this->portalFieldsProvider->getPortalType(),
			'BX_LICENCE' => $this->portalFieldsProvider->getLicenseCode(),
			'BX_REGION' => $this->portalFieldsProvider->getLicenseRegion(),
			'SERVER_NAME' => $this->portalFieldsProvider->getServerName(),
			'BITRIX_USER_ID' => (string)$userId,
		];

		if ($this->networkService->isCloudPortal())
		{
			$networkUserId = $this->networkService->getUserNetworkId($userId);
			if ($networkUserId !== null)
			{
				$fields['BX_NETWORK_USER_ID'] = $networkUserId;
			}
		}

		$email = $this->portalUserProvider->getEmail($userId);
		if ($email !== null && !str_contains($email, '|'))
		{
			$fields['BX_USER_EMAIL'] = $email;
		}

		$fields['LOCALE'] = $this->portalFieldsProvider->getLocale();

		$fields[TransitionSurface::FIELD] = $surface->value;
		$fields += $surfaceFields;
		$fields += [
			'TS' => (string)time(),
			'NONCE' => Random::getString(self::NONCE_LENGTH, true),
		];
		$fields['BX_HASH'] = $this->portalFieldsProvider->sign($fields);

		return $fields;
	}
}
