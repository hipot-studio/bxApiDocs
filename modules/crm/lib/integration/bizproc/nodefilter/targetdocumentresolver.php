<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Activity\Interface\TargetDocumentResolver as TargetDocumentResolverInterface;
use Bitrix\Crm\Integration\BizProc\Document\Dynamic;

final class TargetDocumentResolver implements TargetDocumentResolverInterface
{
	private static array $documentTypeCache = [];

	public function supports(\CBPActivity $activity): bool
	{
		$documentType = self::resolveExpectedDocumentType($activity);

		return ($documentType[0] ?? null) === 'crm';
	}

	public function resolveDocumentId(\CBPActivity $activity): array
	{
		$expectedDocumentType = self::resolveExpectedDocumentType($activity);

		if (self::expectsDynamicType($expectedDocumentType))
		{
			$documentId = Resolver::resolveDynamicDocumentId($activity);
		}
		else
		{
			$expectedType = self::resolveExpectedType($expectedDocumentType);
			$documentId = $expectedType
				? Resolver::resolveDocumentId($activity, $expectedType)
				: Resolver::resolveDocumentId($activity)
			;
		}

		return is_array($documentId) ? $documentId : self::resolveFallbackDocumentId($activity);
	}

	public function resolveDocumentType(array $documentId, array $fallbackDocumentType): array
	{
		$cacheKey = implode('|', $documentId);
		if (isset(self::$documentTypeCache[$cacheKey]))
		{
			return self::$documentTypeCache[$cacheKey];
		}

		static $documentService = null;
		$documentService ??= \CBPRuntime::getRuntime()->getDocumentService();

		$documentType = $documentService->getDocumentType($documentId);
		if (is_array($documentType))
		{
			self::$documentTypeCache[$cacheKey] = $documentType;

			return $documentType;
		}

		return $fallbackDocumentType;
	}

	private static function resolveExpectedType(array $documentType): ?int
	{
		if (($documentType[0] ?? null) !== 'crm')
		{
			return null;
		}

		$entityTypeId = \CCrmOwnerType::ResolveID($documentType[2] ?? '');

		return $entityTypeId > 0 ? $entityTypeId : null;
	}

	private static function expectsDynamicType(array $documentType): bool
	{
		if (($documentType[0] ?? null) !== 'crm')
		{
			return false;
		}

		return ($documentType[1] ?? null) === Dynamic::class;
	}

	private static function resolveExpectedDocumentType(\CBPActivity $activity): array
	{
		$parent = $activity->parent ?? null;
		if ($parent && is_subclass_of($parent, FixedDocumentComplexActivity::class))
		{
			return $parent::getDocumentTypeForNodeAction();
		}

		return $activity->getDocumentType();
	}

	private static function resolveFallbackDocumentId(\CBPActivity $activity): array
	{
		$documentId = $activity->getDocumentId();
		if (is_array($documentId))
		{
			return $documentId;
		}

		$rootDocumentId = $activity->getRootActivity()->getDocumentId();

		return is_array($rootDocumentId) ? $rootDocumentId : [];
	}
}
