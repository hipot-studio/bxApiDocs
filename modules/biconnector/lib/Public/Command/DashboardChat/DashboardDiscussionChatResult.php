<?php

namespace Bitrix\BIConnector\Public\Command\DashboardChat;

use Bitrix\Main\Result;

class DashboardDiscussionChatResult extends Result
{
	private ?int $chatId;
	private string $dialogId;
	private bool $isCreated;

	public function __construct(?int $chatId = null, string $dialogId = '', bool $isCreated = false)
	{
		parent::__construct();
		$this->chatId = $chatId;
		$this->dialogId = $dialogId;
		$this->isCreated = $isCreated;
	}

	public function getChatId(): ?int
	{
		return $this->chatId;
	}

	public function setChatId(?int $chatId): self
	{
		$this->chatId = $chatId;

		return $this;
	}

	public function getDialogId(): string
	{
		return $this->dialogId;
	}

	public function setDialogId(string $dialogId): self
	{
		$this->dialogId = $dialogId;

		return $this;
	}

	public function isCreated(): bool
	{
		return $this->isCreated;
	}

	public function setIsCreated(bool $isCreated): self
	{
		$this->isCreated = $isCreated;

		return $this;
	}
}
