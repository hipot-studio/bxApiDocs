<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Market\Internal\Services\AiSearch\Enum\SearchEngine;

final class Context
{
	private const ID_PATTERN = '/^[A-Za-z0-9_-]{0,64}$/D';
	private const TOKEN_PATTERN = '/^(ai|classic)\.([A-Za-z0-9_-]{0,64})\.([A-Za-z0-9_-]{0,64})$/D';

	private function __construct(
		private readonly SearchEngine $engine,
		private readonly string $searchRequestId,
		private readonly string $resultSetId,
	)
	{
	}

	public static function create(SearchEngine $engine, string $searchRequestId, string $resultSetId): self
	{
		return new self(
			$engine === SearchEngine::Ai ? SearchEngine::Ai : SearchEngine::Classic,
			self::normalizeId($searchRequestId),
			self::normalizeId($resultSetId),
		);
	}

	public static function fromToken(string $token): ?self
	{
		if (preg_match(self::TOKEN_PATTERN, $token, $matches) !== 1)
		{
			return null;
		}

		return new self(SearchEngine::from($matches[1]), $matches[2], $matches[3]);
	}

	public function toToken(): string
	{
		return $this->engine->value . '.' . $this->searchRequestId . '.' . $this->resultSetId;
	}

	public function getEngine(): SearchEngine
	{
		return $this->engine;
	}

	public function getSearchRequestId(): string
	{
		return $this->searchRequestId;
	}

	public function getResultSetId(): string
	{
		return $this->resultSetId;
	}

	private static function normalizeId(string $id): string
	{
		return preg_match(self::ID_PATTERN, $id) === 1 ? $id : '';
	}
}
