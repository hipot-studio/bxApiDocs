<?php

namespace Bitrix\Im\V2\Call;

use Bitrix\Call\JwtCall;
use Bitrix\Im\V2\Rest\PopupDataItem;
use Bitrix\Main\Loader;

class CallToken implements PopupDataItem
{
	protected ?int $chatId = null;
	protected ?int $userId = null;
	protected string $token = '';

	public function __construct(?int $chatId, ?int $userId)
	{
		if (isset($chatId) && Loader::includeModule('call'))
		{
			$this->token = JwtCall::getCallToken($chatId, $userId);
			$this->chatId = $chatId;
			$this->userId = $userId;
		}
	}

	public function update(): void
	{
		if (Loader::includeModule('call'))
		{
			$this->token = \Bitrix\Call\JwtCall::updateCallToken((int)$this->chatId, (int)$this->userId);
		}
	}

	public function getToken(): string
	{
		return $this->token;
	}

	public function getChatId(): ?int
	{
		return $this->chatId;
	}

	public static function getRestEntityName(): string
	{
		return 'callInfo';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'token' => $this->token,
			'chatId' => $this->chatId,
		];
	}

	public function merge(PopupDataItem $item): PopupDataItem
	{
		return $this;
	}
}