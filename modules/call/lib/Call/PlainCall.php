<?php

namespace Bitrix\Call\Call;

use Bitrix\Call\Util;
use Bitrix\Main\Config\Option;
use Bitrix\Call\ControllerClient;
use Bitrix\Call\Settings;


class PlainCall extends \Bitrix\Call\Call
{
	protected $provider = parent::PROVIDER_PLAIN;

	protected function initCall(): void
	{
		if (Settings::isNewCallsEnabled() && Settings::isPlainCallsUseNewScheme())
		{
			return;
		}

		if ($this->getState() == static::STATE_NEW)
		{
			if (empty($this->uuid))
			{
				$this->uuid = Util::generateUUID();
				$this->save();
			}
			(new ControllerClient())->createCall($this);
		}

		parent::initCall();
	}

	protected function finishOnController(): \Bitrix\Main\Result
	{
		// No SCHEME_JWT exception: the balancer keys rooms by chatId, so a room
		// left open after finish swallows the next dial into a dead room (the
		// repeat call never reaches the portal). Whoever finished the call
		// closes the room; runs off-lock in finishSideEffects() with an
		// idempotent retry on failure.
		return (new ControllerClient())->finishCall($this);
	}

	public function getMaxUsers(): int
	{
		return (int)Option::get('call', 'turn_server_max_users');
	}

	/**
	 * Do need to record call.
	 * @return bool
	 */
	public function autoStartRecording(): bool
	{
		return false;
	}
}
