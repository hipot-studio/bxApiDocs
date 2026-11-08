<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto;

/**
 * Unpacked answer of the aiassistant MCP connection contour.
 *
 * Keeps the whole knowledge of the contour payload shape inside this subpackage: consumers read
 * typed getters instead of digging into the foreign response array or its error codes.
 * A refusal carries the contour code as it came, without translation into BI wording.
 */
final class GatewayResult
{
	private function __construct(
		private readonly bool $success,
		private readonly ?string $refusalCode,
		private readonly array $data,
	)
	{
	}

	public static function success(array $data): self
	{
		return new self(true, null, $data);
	}

	public static function refusal(string $refusalCode): self
	{
		return new self(false, $refusalCode, []);
	}

	public function isSuccess(): bool
	{
		return $this->success;
	}

	/**
	 * @return string|null Contour refusal code, null on success.
	 */
	public function getRefusalCode(): ?string
	{
		return $this->refusalCode;
	}

	public function getOutcome(): ?string
	{
		return $this->readString($this->data, 'outcome');
	}

	/**
	 * @return string|null The secret, returned by the contour only in the call that issued it.
	 */
	public function getToken(): ?string
	{
		return $this->readString($this->data, 'token');
	}

	public function getExpiresAt(): ?string
	{
		return $this->readString($this->data, 'expiresAt');
	}

	public function getServerUrl(): ?string
	{
		return $this->readString($this->server(), 'url');
	}

	public function getServerTransport(): ?string
	{
		return $this->readString($this->server(), 'transport');
	}

	public function getServerAuthorization(): ?string
	{
		return $this->readString($this->server(), 'authorization');
	}

	/**
	 * A successful state answer without a connection means the user has no alive token.
	 */
	public function hasConnection(): bool
	{
		return is_array($this->data['connection'] ?? null);
	}

	public function getConnectionTitle(): ?string
	{
		return $this->readString($this->connection(), 'title');
	}

	/**
	 * @return array List of scope names granted to the alive token.
	 */
	public function getConnectionScopes(): array
	{
		return $this->getBlock($this->connection(), 'scopes');
	}

	public function getConnectionExpiresAt(): ?string
	{
		return $this->readString($this->connection(), 'expiresAt');
	}

	public function getConnectionCreatedAt(): ?string
	{
		return $this->readString($this->connection(), 'createdAt');
	}

	private function server(): array
	{
		return $this->getBlock($this->data, 'server');
	}

	private function connection(): array
	{
		return $this->getBlock($this->data, 'connection');
	}

	/**
	 * @psalm-suppress MixedAssignment The contour payload is an untyped array by nature.
	 */
	private function getBlock(array $block, string $key): array
	{
		$value = $block[$key] ?? null;

		return is_array($value) ? $value : [];
	}

	/**
	 * @psalm-suppress MixedAssignment The contour payload is an untyped array by nature.
	 */
	private function readString(array $block, string $key): ?string
	{
		$value = $block[$key] ?? null;

		return is_string($value) ? $value : null;
	}
}
