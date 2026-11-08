<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Details\PageLayout;

use Bitrix\Crm\Entity\EntityEditorConfigScope;

final class PageLayoutId
{
	private const CODE_MASK = "/^(C(?<categoryId>\d+):)?(?<scope>(common|personal|custom))(:(?<customPageLayoutId>(\d+)))?$/";
	private const SCOPE_COMMON = 'common';
	private const SCOPE_PERSONAL = 'personal';
	private const SCOPE_CUSTOM = 'custom';

	private const SCOPE_MAP = [
		self::SCOPE_COMMON => EntityEditorConfigScope::COMMON,
		self::SCOPE_PERSONAL => EntityEditorConfigScope::PERSONAL,
		self::SCOPE_CUSTOM => EntityEditorConfigScope::CUSTOM,
	];

	public function __construct(
		public ?int $categoryId,
		public string $scope,
		public ?int $customPageLayoutId,
	)
	{
	}

	public function getCode(): ?string
	{
		$scope = array_flip(self::SCOPE_MAP)[$this->scope] ?? null;
		if ($scope === null)
		{
			return null;
		}

		if ($this->scope === EntityEditorConfigScope::CUSTOM && $this->customPageLayoutId === null)
		{
			return null;
		}

		$parts = [];
		if ($this->categoryId !== null)
		{
			$parts[] = "C{$this->categoryId}";
		}

		$parts[] = $scope;

		if ($this->scope === EntityEditorConfigScope::CUSTOM && $this->customPageLayoutId !== null)
		{
			$parts[] = $this->customPageLayoutId;
		}

		return implode(':', $parts);
	}

	public static function fromCode(string $code): ?self
	{
		if (!preg_match(self::CODE_MASK, $code, $matches))
		{
			return null;
		}

		$categoryId = $matches['categoryId'] ?? null;
		$categoryId = is_numeric($categoryId) ? (int)$categoryId : null;

		$scope = self::SCOPE_MAP[$matches['scope'] ?? null] ?? null;
		if ($scope === null)
		{
			return null;
		}

		$customPageLayoutId = null;
		if ($scope === EntityEditorConfigScope::CUSTOM)
		{
			$customPageLayoutId = $matches['customPageLayoutId'] ?? null;
			if ($customPageLayoutId === null)
			{
				return null;
			}

			$customPageLayoutId = (int)$customPageLayoutId;
		}

		return new self($categoryId, $scope, $customPageLayoutId);
	}
}
