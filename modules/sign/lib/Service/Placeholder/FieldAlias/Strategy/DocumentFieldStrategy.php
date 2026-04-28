<?php

namespace Bitrix\Sign\Service\Placeholder\FieldAlias\Strategy;

use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Service\Placeholder\FieldAlias\FieldNameTransformer;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\FieldType;

class DocumentFieldStrategy extends AbstractAliasStrategy
{
	private const DOC_PREFIX = 'Doc';
	private const EXT_PREFIX = 'Ext';
	private const BLOCK_CODE = BlockCode::B2E_MY_REFERENCE;
	private const UF_DOC_PREFIX = 'SMART_B2E_DOC_UF_CRM_SMART_B2E_DOC_';
	
	public function getAliasPrefixes(): array
	{
		return [self::DOC_PREFIX, self::EXT_PREFIX];
	}
	
	private const EXTERNAL_FIELDS = [
		'Id' => [
			'blockCode' => BlockCode::B2E_EXTERNAL_ID,
			'fieldType' => FieldType::EXTERNAL_ID,
			'fieldCode' => '__b2eexternalid',
		],
		'Date' => [
			'blockCode' => BlockCode::B2E_EXTERNAL_DATE_CREATE,
			'fieldType' => FieldType::EXTERNAL_DATE,
			'fieldCode' => '__b2edocumentdate',
		],
	];
	
	private const EXTERNAL_REVERSE = [
		BlockCode::B2E_EXTERNAL_ID => 'Id',
		BlockCode::B2E_EXTERNAL_DATE_CREATE => 'Date',
	];
	
	private const DOCUMENT_FIELDS = [
		'Title' => 'SMART_B2E_DOC_TITLE',
		'XmlId' => 'SMART_B2E_DOC_XML_ID',
		'WebformId' => 'SMART_B2E_DOC_WEBFORM_ID',
		'BeginDate' => 'SMART_B2E_DOC_BEGINDATE',
		'CloseDate' => 'SMART_B2E_DOC_CLOSEDATE',
		'StageId' => 'SMART_B2E_DOC_STAGE_ID',
		'SourceId' => 'SMART_B2E_DOC_SOURCE_ID',
		'SourceDescription' => 'SMART_B2E_DOC_SOURCE_DESCRIPTION',
		'LastActivityBy' => 'SMART_B2E_DOC_LAST_ACTIVITY_BY',
		'LastActivityTime' => 'SMART_B2E_DOC_LAST_ACTIVITY_TIME',
		'Num' => 'SMART_B2E_DOC_NUMBER',
	];
	
	private const DOCUMENT_REVERSE = [
		'SMART_B2E_DOC_TITLE' => 'Title',
		'SMART_B2E_DOC_XML_ID' => 'XmlId',
		'SMART_B2E_DOC_WEBFORM_ID' => 'WebformId',
		'SMART_B2E_DOC_BEGINDATE' => 'BeginDate',
		'SMART_B2E_DOC_CLOSEDATE' => 'CloseDate',
		'SMART_B2E_DOC_STAGE_ID' => 'StageId',
		'SMART_B2E_DOC_SOURCE_ID' => 'SourceId',
		'SMART_B2E_DOC_SOURCE_DESCRIPTION' => 'SourceDescription',
		'SMART_B2E_DOC_LAST_ACTIVITY_BY' => 'LastActivityBy',
		'SMART_B2E_DOC_LAST_ACTIVITY_TIME' => 'LastActivityTime',
		'SMART_B2E_DOC_NUMBER' => 'Num',
	];

	public function supportsFieldName(string $fieldName): bool
	{
		$parsed = $this->parseFieldName($fieldName);
		
		if (!$this->hasRequiredParsedValues($parsed, ['blockCode', 'fieldCode']))
		{
			return false;
		}

		$blockCode = $parsed['blockCode'];
		$fieldCode = $parsed['fieldCode'];
		
		if (in_array($blockCode, [BlockCode::B2E_EXTERNAL_ID, BlockCode::B2E_EXTERNAL_DATE_CREATE], true))
		{
			return str_starts_with($fieldCode, '__b2e');
		}
		
		if ($blockCode === self::BLOCK_CODE)
		{
			if (str_starts_with($fieldCode, 'SMART_B2E_DOC_'))
			{
				if (isset(self::DOCUMENT_REVERSE[$fieldCode]))
				{
					return true;
				}
				
				if (str_starts_with($fieldCode, self::UF_DOC_PREFIX))
				{
					return true;
				}
			}
		}
		
		return false;
	}

	public function supportsAlias(string $alias): bool
	{
		return str_starts_with($alias, self::DOC_PREFIX . '.')
			|| str_starts_with($alias, self::EXT_PREFIX . '.');
	}

	public function fieldNameToAlias(string $fieldName, AliasContext $context): ?string
	{
		$parsed = $this->parseFieldName($fieldName);
		
		if (!$this->hasRequiredParsedValues($parsed, ['blockCode', 'fieldCode'], ['party']))
		{
			return null;
		}

		$blockCode = $parsed['blockCode'];
		$fieldCode = $parsed['fieldCode'];
		$party = $parsed['party'];
		$roleName = $this->getRoleName($context, $party);
		
		if (isset(self::EXTERNAL_REVERSE[$blockCode]))
		{
			$shortName = self::EXTERNAL_REVERSE[$blockCode];

			return self::EXT_PREFIX . ".{$roleName}.{$shortName}";
		}
		
		if (isset(self::DOCUMENT_REVERSE[$fieldCode]))
		{
			$shortName = self::DOCUMENT_REVERSE[$fieldCode];

			return self::DOC_PREFIX . ".{$roleName}.{$shortName}";
		}
		
		if (str_starts_with($fieldCode, self::UF_DOC_PREFIX))
		{
			$timestamp = $this->extractTimestampFromFieldCode($fieldCode);
			
			if ($timestamp === null)
			{
				return null;
			}

			if (!$this->hasRequiredParsedValues($parsed, ['fieldType']))
			{
				return null;
			}

			$fieldType = $parsed['fieldType'];
			
			$typeShortcut = self::TYPE_SHORTCUTS[$fieldType] ?? ucfirst($fieldType);
			
			return self::DOC_PREFIX . ".{$roleName}.{$typeShortcut}{$timestamp}";
		}

		return null;
	}

	public function aliasToFieldName(string $alias, AliasContext $context): ?string
	{
		$parts = explode('.', $alias);
		
		if (count($parts) !== FieldNameTransformer::FIELD_PARTS_COUNT)
		{
			return null;
		}

		[$prefix, $roleName, $shortName] = $parts;

		$party = $this->getPartyFromRoleName($context, $roleName);
		
		if ($party === null)
		{
			return null;
		}
		
		if ($prefix === self::EXT_PREFIX)
		{
			return $this->resolveExternalField($shortName, $party);
		}
		
		if ($prefix === self::DOC_PREFIX)
		{
			$fieldName = $this->resolveDocumentField($shortName, $party);
			
			if ($fieldName !== null)
			{
				return $fieldName;
			}
			
			if (preg_match('/^(\w+?)(\d{13})$/', $shortName, $matches))
			{
				$typeShortcut = $matches[1];
				$timestamp = $matches[2];
				
				$fieldType = array_search($typeShortcut, self::TYPE_SHORTCUTS, true);
				
				if ($fieldType === false)
				{
					$fieldType = strtolower($typeShortcut);
				}
				
				$fieldCode = self::UF_DOC_PREFIX . $timestamp;
				
				return $this->createFieldName(
					blockCode: self::BLOCK_CODE,
					fieldType: $fieldType,
					party: $party,
					fieldCode: $fieldCode,
				);
			}
		}

		return null;
	}
	
	private function resolveExternalField(string $shortName, int $party): ?string
	{
		$fieldData = self::EXTERNAL_FIELDS[$shortName] ?? null;
		
		if ($fieldData === null)
		{
			return null;
		}

		return $this->createFieldName(
			blockCode: $fieldData['blockCode'],
			fieldType: $fieldData['fieldType'],
			party: $party,
			fieldCode: $fieldData['fieldCode'],
		);
	}
	
	private function resolveDocumentField(string $shortName, int $party): ?string
	{
		$fieldCode = self::DOCUMENT_FIELDS[$shortName] ?? null;
		
		if ($fieldCode === null)
		{
			return null;
		}
		
		$fieldType = $this->getDocumentFieldType($fieldCode);

		return $this->createFieldName(
			blockCode: BlockCode::B2E_MY_REFERENCE,
			fieldType: $fieldType,
			party: $party,
			fieldCode: $fieldCode,
		);
	}
	
	/**
	 * Get field type for the given document field code
	 * @param string $fieldCode Document field code (e.g., SMART_B2E_DOC_TITLE, SMART_B2E_DOC_BEGINDATE, etc.)
	 * @return FieldType::DATE|FieldType::INTEGER|FieldType::LIST|FieldType::STRING
	 */
	private function getDocumentFieldType(string $fieldCode): string
	{
		return match (true)
		{
			str_contains($fieldCode, 'DATE') || str_contains($fieldCode, 'TIME') => FieldType::DATE,
			str_contains($fieldCode, 'WEBFORM_ID') => FieldType::INTEGER,
			str_contains($fieldCode, 'STAGE_ID') || str_contains($fieldCode, 'SOURCE_ID') => FieldType::LIST,
			default => FieldType::STRING,
		};
	}
}
