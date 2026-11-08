<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Intranet\Enum\UserRole;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Deeplink;
use Bitrix\Mobile\Feature\QrLoginFeature;
use Bitrix\Mobile\Provider\QrGenerator;

class QrAuth extends JsonController
{
	private const DEFAULT_TTL = 60;
	private const MAX_TTL = 300;
	private const LINK_INTENT = 'mobapp_qr_auth';
	private const QUIET_ZONE_MULTIPLIER = 1;

	public function configureActions(): array
	{
		return [
			'getLoginCode' => [
				'+prefilters' => [
					new Scope(Scope::AJAX),
					new CloseSession(),
				],
			],
		];
	}

	public function getLoginCodeAction(?int $ttl = null, bool $isDarkMode = false): ?array
	{
		if (!Feature::isEnabled(QrLoginFeature::class))
		{
			return $this->fail('Login code feature is disabled', 403);
		}

		if ($this->isGuestSession())
		{
			return $this->fail('Login code is not available for a guest session', 403);
		}

		$userId = (int)$this->getCurrentUser()?->getId();
		$appliedTtl = $this->resolveTtl($ttl);

		$image = (new QrGenerator(
			Deeplink::getAuthLink(self::LINK_INTENT, $userId, $appliedTtl),
			$isDarkMode,
			quietZoneMultiplier: self::QUIET_ZONE_MULTIPLIER,
			size: null,
			padding: 0,
		))->getContent();

		if ($image === '')
		{
			return $this->fail('QR code generation failed', 500);
		}

		return [
			'image' => $image,
			'ttl' => $appliedTtl,
		];
	}

	private function resolveTtl(?int $ttl): int
	{
		if ($ttl === null || $ttl <= 0)
		{
			return self::DEFAULT_TTL;
		}

		return min($ttl, self::MAX_TTL);
	}

	private function isGuestSession(): bool
	{
		global $USER;

		return isset($USER) && $USER->GetParam('EXTERNAL_AUTH_ID') === UserRole::IM_GUEST->value;
	}

	private function fail(string $message, int $httpStatus): ?array
	{
		Context::getCurrent()->getResponse()->setStatus($httpStatus);
		$this->addError(new Error($message, $httpStatus));

		return null;
	}
}
