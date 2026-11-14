<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Market\Internal\Services\AiSearch\Enum\CorrectionMode;

final class SearchQuery
{
	private function __construct(
		private readonly string $text,
		private readonly int $page,
		private readonly string $area,
		private readonly array $order,
		private readonly CorrectionMode $correctionMode,
	)
	{
	}

	public static function create(string $text, int $page, string $area, array $order, CorrectionMode $correctionMode): self
	{
		return new self(
			trim($text),
			max(1, $page),
			$area,
			$order,
			$correctionMode,
		);
	}

	public function getText(): string
	{
		return $this->text;
	}

	public function getPage(): int
	{
		return $this->page;
	}

	public function getArea(): string
	{
		return $this->area;
	}

	public function getOrder(): array
	{
		return $this->order;
	}

	public function getCorrectionMode(): CorrectionMode
	{
		return $this->correctionMode;
	}
}
