<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

use Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

final class LayoutContext
{
	private function __construct(
		private readonly int $entityTypeId,
		private readonly ?int $categoryId,
		private readonly LayoutMode $mode,
		private readonly LayoutVariant $variant,
	)
	{
	}

	public static function create(
		int $entityTypeId,
		?int $categoryId,
		LayoutMode $mode,
		LayoutVariant $variant = LayoutVariant::General,
	): Result
	{
		if (
			$variant === LayoutVariant::Returning
			&& ($entityTypeId !== CCrmOwnerType::Lead || $categoryId !== null)
		)
		{
			return self::unsupportedContextResult();
		}

		$internalResult = Context::createForLayout(
			$entityTypeId,
			$categoryId,
			$mode->value,
			$variant === LayoutVariant::Returning ? $variant->value : null,
		);
		if (!$internalResult->isSuccess())
		{
			return self::unsupportedContextResult();
		}

		return (new Result())->setData([
			'context' => new self($entityTypeId, $categoryId, $mode, $variant),
		]);
	}

	public function getEntityTypeId(): int
	{
		return $this->entityTypeId;
	}

	public function getCategoryId(): ?int
	{
		return $this->categoryId;
	}

	public function getMode(): LayoutMode
	{
		return $this->mode;
	}

	public function getVariant(): LayoutVariant
	{
		return $this->variant;
	}

	private static function unsupportedContextResult(): Result
	{
		return (new Result())->addError(
			new Error('The layout context is not supported.', 'UNSUPPORTED_CONTEXT'),
		);
	}
}
