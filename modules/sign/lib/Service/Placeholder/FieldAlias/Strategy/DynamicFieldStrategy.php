<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasRoleResolver;
use Bitrix\Sign\Service\Providers\MemberDynamicFieldInfoProvider;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\BlockCode;

class DynamicFieldStrategy extends AbstractAliasStrategy
{
	private const ALIAS_PREFIX = 'Uf';
	private const BLOCK_CODE = BlockCode::EMPLOYEE_DYNAMIC;
	private const UF_PREFIX = 'UF_SIGN_MEMBER_DYNAMIC_';

	private MemberDynamicFieldInfoProvider $dynamicFieldProvider;

	public function __construct(
		?MemberDynamicFieldInfoProvider $dynamicFieldProvider = null,
		?AliasRoleResolver $aliasRoleResolver = null,
	)
	{
		parent::__construct($aliasRoleResolver);
		$this->dynamicFieldProvider = $dynamicFieldProvider
			?? Container::instance()->getMemberDynamicFieldProvider();
	}

	public function getAliasPrefixes(): array
	{
		return [self::ALIAS_PREFIX];
	}

	public function supportsFieldName(string $fieldName): bool
	{
		return $this->dynamicFieldProvider->isFieldCodeMemberDynamicField($fieldName);
	}

	public function supportsAlias(string $alias): bool
	{
		return str_starts_with($alias, self::ALIAS_PREFIX . '.');
	}

	public function fieldNameToAlias(string $fieldName, AliasContext $context): ?string
	{
		if (!$this->supportsFieldName($fieldName))
		{
			return null;
		}

		$parsed = $this->parseFieldName($fieldName);
		
		if (!$this->hasRequiredParsedValues($parsed, ['fieldCode', 'fieldType'], ['party']))
		{
			return null;
		}

		$fieldCode = $parsed['fieldCode'];
		$fieldType = $parsed['fieldType'];
		$party = $parsed['party'];
		
		if (!preg_match('/' . preg_quote(self::UF_PREFIX, '/') . '(\d{13})/', $fieldCode, $matches))
		{
			return null;
		}

		$timestamp = $matches[1];
		$roleName = $this->getRoleName($context, $party);
		
		$typeShortcut = self::TYPE_SHORTCUTS[$fieldType] ?? ucfirst($fieldType);

		return self::ALIAS_PREFIX . ".{$roleName}.{$typeShortcut}{$timestamp}";
	}

	public function aliasToFieldName(string $alias, AliasContext $context): ?string
	{
		$aliasData = $this->parseAliasFieldPartWithParty($alias, $context, self::ALIAS_PREFIX);
		if ($aliasData === null)
		{
			return null;
		}

		$fieldPart = $aliasData['fieldPart'];
		$party = $aliasData['party'];
		
		if (!preg_match('/^(\w+?)(\d{' . self::DYNAMIC_FIELD_TIMESTAMP_LENGTH . '})$/', $fieldPart, $matches))
		{
			return null;
		}

		$typeShortcut = $matches[1];
		$timestamp = $matches[2];
		
		$fieldType = array_search($typeShortcut, self::TYPE_SHORTCUTS, true);
		
		if ($fieldType === false)
		{
			$fieldType = strtolower($typeShortcut);
		}

		$fieldCode = self::UF_PREFIX . $timestamp;

		return $this->createFieldName(
			blockCode: self::BLOCK_CODE,
			fieldType: $fieldType,
			party: $party,
			fieldCode: $fieldCode,
		);
	}
}
