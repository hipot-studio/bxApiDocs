<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\EntityEditor\Layout;

use Bitrix\Crm\CustomerType;
use Bitrix\Crm\Entity\EntityEditorConfig;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * @internal
 */
final class Context
{
	public const MODE_CREATE = 'create';
	public const MODE_UPDATE = 'update';
	public const CRM_CATEGORY_ID_KEY = 'CATEGORY_ID';
	public const UI_MODULE_CATEGORY = 'crm';
	public const OPTION_CATEGORY = 'crm.entity.editor';

	private const LEAD_CUSTOMER_TYPE_GENERAL = 'general';
	private const LEAD_CUSTOMER_TYPE_RETURNING = 'returning';

	private function __construct(
		public readonly int $entityTypeId,
		public readonly ?int $categoryId,
		public readonly ?int $customerType,
		public readonly string $mode,
	)
	{
	}

	public static function createForLayout(
		int $entityTypeId,
		?int $categoryId,
		string $mode,
		?string $leadCustomerType = null,
	): Result
	{
		if ($entityTypeId !== CCrmOwnerType::Lead && $leadCustomerType !== null)
		{
			return (new Result())->addError(
				new Error(
					'Lead customer type is only supported for lead layouts.',
					ErrorCode::UNSUPPORTED_VARIANT,
				),
			);
		}

		$customerType = match ($leadCustomerType) {
			null,
			self::LEAD_CUSTOMER_TYPE_GENERAL => CustomerType::GENERAL,
			self::LEAD_CUSTOMER_TYPE_RETURNING => CustomerType::RETURNING,
			default => null,
		};

		return self::create(
			$entityTypeId,
			$categoryId,
			$customerType,
			$mode,
		);
	}

	public static function create(
		int $entityTypeId,
		?int $categoryId,
		?int $customerType,
		string $mode,
	): Result
	{
		$result = new Result();

		if (!in_array($mode, [self::MODE_CREATE, self::MODE_UPDATE], true))
		{
			return $result->addError(
				new Error(
					"Layout mode {$mode} is not supported.",
					ErrorCode::UNSUPPORTED_VARIANT,
				),
			);
		}

		if (!EntityEditorConfig::isEntityTypeSupported($entityTypeId))
		{
			return $result->addError(
				new Error(
					"Entity type {$entityTypeId} is not supported by the CRM entity editor.",
					ErrorCode::UNSUPPORTED_ENTITY_TYPE,
				),
			);
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return $result->addError(
				new Error(
					"Factory for entity type {$entityTypeId} is not available.",
					ErrorCode::UNSUPPORTED_ENTITY_TYPE,
				),
			);
		}

		if ($factory->isCategoriesSupported())
		{
			if ($categoryId === null || !$factory->isCategoryExists($categoryId))
			{
				return $result->addError(
					new Error(
						self::categoryErrorMessage($entityTypeId, $categoryId),
						ErrorCode::INVALID_CATEGORY,
					),
				);
			}
		}
		elseif ($categoryId !== null)
		{
			return $result->addError(
				new Error(
					self::categoryErrorMessage($entityTypeId, $categoryId),
					ErrorCode::INVALID_CATEGORY,
				),
			);
		}

		if (
			$entityTypeId === CCrmOwnerType::Lead
			&& !in_array($customerType, [CustomerType::GENERAL, CustomerType::RETURNING], true)
		)
		{
			return $result->addError(
				new Error(
					'Only GENERAL and RETURNING customer types are supported for lead layouts.',
					ErrorCode::UNSUPPORTED_VARIANT,
				),
			);
		}

		$context = new self(
			entityTypeId: $entityTypeId,
			categoryId: $categoryId,
			customerType: $entityTypeId === CCrmOwnerType::Lead ? $customerType : null,
			mode: $mode,
		);
		$result->setData(['context' => $context]);

		return $result;
	}

	private static function categoryErrorMessage(int $entityTypeId, ?int $categoryId): string
	{
		$category = $categoryId === null ? 'null' : (string)$categoryId;

		return "Category {$category} is not valid for entity type {$entityTypeId}.";
	}
}
