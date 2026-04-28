<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasRoleResolver;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkFieldService;
use Bitrix\Sign\Service\Container;
use Bitrix\HumanResources\Type\HcmLink\FieldType as HcmFieldType;
use Bitrix\Sign\Type\BlockCode;

class HcmLinkFieldStrategy extends AbstractAliasStrategy implements PreloadableStrategyInterface
{
	private const ALIAS_PREFIX = '1C';

	private HcmLinkFieldService $hcmLinkFieldService;

	public function __construct(
		?HcmLinkFieldService $hcmLinkFieldService = null,
		?AliasRoleResolver $aliasRoleResolver = null,
	)
	{
		parent::__construct($aliasRoleResolver);
		$this->hcmLinkFieldService = $hcmLinkFieldService ?? Container::instance()->getHcmLinkFieldService();
	}

	public function getAliasPrefixes(): array
	{
		return [self::ALIAS_PREFIX];
	}

	public function supportsFieldName(string $fieldName): bool
	{
		return str_starts_with($fieldName, HcmLinkFieldService::FIELD_PREFIX);
	}

	public function supportsAlias(string $alias): bool
	{
		return str_starts_with($alias, self::ALIAS_PREFIX . '.');
	}

	public function preloadForAliases(array $aliases, AliasContext $context): void
	{
		if ($context->hcmLinkCompanyId === null)
		{
			return;
		}

		foreach ($aliases as $alias)
		{
			if ($this->supportsAlias($alias))
			{
				$this->hcmLinkFieldService->preloadByCompanyId($context->hcmLinkCompanyId);

				return;
			}
		}
	}

	public function preloadForFieldNames(array $fieldNames): void
	{
		$ids = [];

		foreach ($fieldNames as $fieldName)
		{
			if (!$this->supportsFieldName($fieldName))
			{
				continue;
			}

			$parsed = $this->hcmLinkFieldService->parseName($fieldName);

			if ($parsed !== null)
			{
				$ids[] = $parsed->id;
			}
		}

		if (!empty($ids))
		{
			$this->hcmLinkFieldService->getFieldsByIds($ids);
		}
	}

	public function fieldNameToAlias(string $fieldName, AliasContext $context): ?string
	{
		$parsed = $this->hcmLinkFieldService->parseName($fieldName);

		if ($parsed === null)
		{
			return null;
		}

		$field = $this->hcmLinkFieldService->getFieldById($parsed->id);
		if ($field === null)
		{
			return null;
		}

		$roleName = $this->getRoleName($context, $parsed->party);
		$aliasPart = $this->codeToShortAlias($field->field);

		return self::ALIAS_PREFIX . ".{$roleName}.{$aliasPart}";
	}

	public function aliasToFieldName(string $alias, AliasContext $context): ?string
	{
		$aliasData = $this->parseAliasFieldPartWithParty($alias, $context, self::ALIAS_PREFIX);
		if ($aliasData === null)
		{
			return null;
		}

		$aliasPart = $aliasData['fieldPart'];
		$party = $aliasData['party'];

		if ($context->hcmLinkCompanyId === null)
		{
			return null;
		}

		$code = $this->shortAliasToCode($aliasPart);

		$field = $this->hcmLinkFieldService->getFieldByCode($context->hcmLinkCompanyId, $code);
		if ($field === null && $code !== $aliasPart)
		{
			$field = $this->hcmLinkFieldService->getFieldByCode($context->hcmLinkCompanyId, $aliasPart);
		}
		if ($field === null)
		{
			return null;
		}

		$fieldSelectorName = $this->hcmLinkFieldService->buildFieldSelectorName($field, $party);

		return $this->createFieldName(
			blockCode: BlockCode::B2E_HCMLINK_REFERENCE,
			fieldType: $this->getFieldTypeStringByHcmType($field->type),
			party: $party,
			fieldCode: $fieldSelectorName,
		);
	}

	private function codeToShortAlias(string $code): string
	{
		return HcmLinkFieldAliasMap::getCodeToAlias()[$code] ?? $code;
	}

	private function shortAliasToCode(string $alias): string
	{
		return HcmLinkFieldAliasMap::getAliasToCode()[$alias] ?? $alias;
	}

	private function getFieldTypeStringByHcmType(HcmFieldType $type): string
	{
		return match ($type)
		{
			HcmFieldType::FIRST_NAME => 'firstname',
			HcmFieldType::LAST_NAME => 'lastname',
			HcmFieldType::PATRONYMIC_NAME => 'patronymic',
			HcmFieldType::POSITION => 'position',
			HcmFieldType::SNILS => 'snils',
			HcmFieldType::PHONE => 'phone',
			HcmFieldType::EMAIL => 'email',
			HcmFieldType::BIRTHDAY => 'date',
			HcmFieldType::INN => 'string',
			HcmFieldType::ADDRESS => 'address',
			HcmFieldType::DEPARTMENT => 'string',
			HcmFieldType::DOCUMENT_REGISTRATION_NUMBER => 'string',
			HcmFieldType::DOCUMENT_UID => 'string',
			HcmFieldType::DOCUMENT_DATE => 'date',
			HcmFieldType::STRING => 'string',
			default => 'string',
		};
	}
}
