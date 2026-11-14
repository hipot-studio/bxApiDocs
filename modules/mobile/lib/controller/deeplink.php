<?php
namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\ModuleManager;
use Bitrix\Mobile\Internal\Entity\Deeplink\Payload;
use Bitrix\Mobile\Public\Provider\DeeplinkProvider;

class Deeplink extends Controller
{
	/**
	 * The payload arrives as the caller wrote it, so this is where it becomes a value of the module
	 * ({@see Payload}) or a refusal: a caller that named an owner nobody knows would otherwise get a
	 * working link and never learn that nothing was bound to it.
	 *
	 * @param array|null $payload ['module' => owner of the payload, 'data' => anything the owner understands]
	 */
	public function getAction($intent, int $ttl = null, ?array $payload = null): array
	{
		global $USER;

		$id = $USER->getId();
		if(intval($id) <= 0)
		{
			parent::addError(new Error('User is not authorized', 401));
			return [];
		}

		$carriedPayload = $payload === null ? null : Payload::fromRequest($payload);
		if ($payload !== null && !$this->isPayloadOwnerKnown($carriedPayload))
		{
			parent::addError(new Error('Unknown payload module', 400));
			return [];
		}

		return ['link' => (new DeeplinkProvider())->getAuthLink((string)$intent, (int)$id, $ttl, $carriedPayload)];
	}

	private function isPayloadOwnerKnown(?Payload $payload): bool
	{
		return $payload !== null && ModuleManager::isModuleInstalled($payload->module);
	}
}
