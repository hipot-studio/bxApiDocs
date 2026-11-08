<?php

namespace Bitrix\Call\Call;

use Bitrix\Call\Util;
use Bitrix\Main\Error;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\JWT;
use Bitrix\Call\ControllerClient;


class BitrixCall extends \Bitrix\Call\Call
{
	public const
		ROOM_TYPE_SMALL = 1,
		ROOM_TYPE_BIG = 3,
		ROOM_TYPE_1TO1 = 4
	;

	protected $provider = parent::PROVIDER_BITRIX;

	/**
	 * @return void
	 */
	protected function initCall(): void
	{
		if ($this->scheme === self::SCHEME_JWT)
		{
			return;
		}

		if (!$this->endpoint)
		{
			$this->uuid = Util::generateUUID();
			$this->secretKey = Random::getString(10, true);

			if (!$this->getId())
			{
				$this->save();
			}

			$createResult = (new ControllerClient())->createCall($this);

			if (!$createResult->isSuccess())
			{
				parent::finish();
				$this->addErrors($createResult->getErrors());

				return;
			}
			$callData = $createResult->getData();
			if (!$callData['endpoint'])
			{
				parent::finish();
				$this->addError(new Error('Empty endpoint', 'empty_endpoint'));

				return;
			}

			$this->setEndpoint($callData['endpoint']);
			$this->save();
		}

		parent::initCall();
	}

	protected function getControllerClient(): ControllerClient
	{
		return new ControllerClient();
	}

	protected function finishOnController(): \Bitrix\Main\Result
	{
		// finishCall() tears the room down by UUID and does not need the endpoint, so
		// gate only on the classic scheme. Keying on $this->endpoint would leak a room
		// on the controller when createCall() succeeded but the endpoint was never
		// persisted — the empty-endpoint response branch in initCall(), or a crash
		// between createCall() and setEndpoint()/save(). The Result is returned so
		// finishSideEffects() can retry a failed teardown.
		if ($this->scheme !== self::SCHEME_JWT)
		{
			return $this->getControllerClient()->finishCall($this);
		}

		return new \Bitrix\Main\Result();
	}

	protected function generateJwt(int $userId): string
	{
		return JWT::encode(
			[
				'uuid' => $this->getUuid(),
				'userId' => (string)$userId,
			],
			$this->getSecretKey()
		);
	}

	public function getConnectionData(int $userId): array
	{
		return [
			'endpoint' => $this->endpoint ?: null,
			'jwt' => $this->generateJwt($userId),
		];
	}

	public function getMaxUsers(): int
	{
		return \Bitrix\Call\Settings::getMaxCallServerParticipants();
	}
}
