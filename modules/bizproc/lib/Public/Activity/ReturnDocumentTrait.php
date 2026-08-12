<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;

trait ReturnDocumentTrait
{
	protected static function getReturnDocumentFieldName(): string
	{
		return 'ReturnDocument';
	}

	protected static function buildReturnDocumentProperties(?array $document = null): array
	{
		return [
			static::getReturnDocumentFieldName() => static::buildReturnDocumentMapType($document),
		];
	}

	protected static function buildReturnDocumentMapType(?array $document = null): array
	{
		return [
			'Name' => static::getReturnDocumentTitle($document),
			'Type' => FieldType::DOCUMENT,
			'Default' => $document,
		];
	}

	protected static function getReturnDocumentTitle(?array $document = null): string
	{
		return $document
			? static::getDocumentName($document)
			: static::getDefaultReturnDocumentTitle()
		;
	}

	protected static function getDefaultReturnDocumentTitle(): string
	{
		return Loc::getMessage('BIZPROC_PUBLIC_ACTIVITY_RETURN_DOCUMENT_TITLE') ?? '';
	}

	protected static function getDocumentName(array $documentType): string
	{
		$name = \CBPRuntime::getRuntime()->getDocumentService()->getDocumentTypeName($documentType);

		return \CBPHelper::stringify($name);
	}
}
