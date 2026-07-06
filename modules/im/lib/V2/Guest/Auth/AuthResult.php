<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Result;

/**
 * Result object for guest authentication operations.
 *
 * Contains authenticated user, their token, and optionally the target chat.
 */
class AuthResult extends Result
{
	protected ?User $user = null;
	protected ?string $token = null;
	protected ?Chat $chat = null;
	protected ?JoinStatus $joinStatus = null;

	public function getUser(): ?User
	{
		return $this->user;
	}

	public function setUser(User $user): static
	{
		$this->user = $user;

		return $this;
	}

	public function getToken(): ?string
	{
		return $this->token;
	}

	public function setToken(?string $token): static
	{
		$this->token = $token;

		return $this;
	}

	public function getChat(): ?Chat
	{
		return $this->chat;
	}

	public function setChat(Chat $chat): static
	{
		$this->chat = $chat;

		return $this;
	}

	public function getJoinStatus(): ?JoinStatus
	{
		return $this->joinStatus;
	}

	public function setJoinStatus(JoinStatus $joinStatus): static
	{
		$this->joinStatus = $joinStatus;

		return $this;
	}
}
