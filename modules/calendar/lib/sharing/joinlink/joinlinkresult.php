<?php

namespace Bitrix\Calendar\Sharing\JoinLink;

use Bitrix\Main\Error;

final class JoinLinkResult
{
	public const BRANCH_LEGACY_CONFERENCE = 'legacy_conference';
	public const BRANCH_GUEST_CHAT = 'guest_chat';

	/**
	 * @param Error[] $errors
	 */
	private function __construct(
		private readonly bool $success,
		private readonly string $branch,
		private readonly ?string $url,
		private readonly ?int $chatId,
		private readonly array $errors,
	)
	{
	}

	public static function success(string $branch, string $url, ?int $chatId = null): self
	{
		return new self(true, $branch, $url, $chatId, []);
	}

	public static function successChat(string $branch, int $chatId): self
	{
		return new self(true, $branch, null, $chatId, []);
	}

	/**
	 * @param Error[] $errors
	 */
	public static function failure(string $branch, array $errors = []): self
	{
		return new self(false, $branch, null, null, $errors);
	}

	public function isSuccess(): bool
	{
		return $this->success;
	}

	public function getBranch(): string
	{
		return $this->branch;
	}

	public function getUrl(): ?string
	{
		return $this->url;
	}

	public function getChatId(): ?int
	{
		return $this->chatId;
	}

	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}
}
