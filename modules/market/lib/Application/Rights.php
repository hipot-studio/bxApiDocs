<?php

declare(strict_types=1);

namespace Bitrix\Market\Application;

use Bitrix\Market\Internal\Services\Application\ScopeFactory;

class Rights
{
	private array $rights = [];
	private int $quantityToShow = 0;
	private bool $showMoreButton = false;

	public function __construct(array $rights)
	{
		$this->rights = static::prepare($rights);

		usort($this->rights, [$this, 'sort']);

		$currentLength = 0;
		$maxLength = 130;

		foreach ($this->rights as $right)
		{
			$currentLength += mb_strlen($right['TITLE']);

			if ($currentLength > $maxLength)
			{
				$this->showMoreButton = true;
			}
			else
			{
				$this->quantityToShow++;
			}
		}
	}

	private function sort($a, $b): int
	{
		$lengthA = mb_strlen($a['TITLE']);
		$lengthB = mb_strlen($b['TITLE']);

		if ($lengthA === $lengthB)
		{
			return 0;
		}

		return ($lengthA < $lengthB) ? -1 : 1;
	}

	public function getInfo(): array
	{
		return $this->rights;
	}

	public function getQuantityToShow(): int
	{
		return $this->quantityToShow;
	}

	public function isShowMoreButton(): bool
	{
		return $this->showMoreButton;
	}

	public static function prepare(array $rights): array
	{
		$result = [];

		$scopeFactory = new ScopeFactory();

		foreach ($rights as $key => $scope)
		{
			$result[$key] = $scopeFactory->create($key, $scope)->toArray();
		}

		return $result;
	}
}
