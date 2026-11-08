<?php

declare(strict_types=1);

use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Bizproc\Public\Activity\BaseComplexActivity;
use Bitrix\Crm\Integration\BizProc\Document\Dynamic;
use Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScope;
use Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScopePolicy;
use Bitrix\Crm\Integration\BizProc\NodeFilter\Resolver;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!Loader::includeModule('crm'))
{
	return;
}

Loc::loadMessages(__FILE__);

class CBPCrmDynamicComplexActivity extends BaseComplexActivity implements FixedDocumentComplexActivity
{
	private const CREATE_DYNAMIC_ACTIVITY_TYPE = 'crmcreatedynamicactivity';

	public static function validateProperties($arTestProperties = [], ?\CBPWorkflowTemplateUser $user = null): array
	{
		$errors = parent::validateProperties($arTestProperties, $user);
		$scope = static::getDynamicTypeScope();
		if ($scope === null)
		{
			return $errors;
		}

		try
		{
			Resolver::validateSettingsScope(
				$arTestProperties['FilterSettings'] ?? null,
				$scope,
				static::createDynamicTypeScopePolicy(),
			);
		}
		catch (\InvalidArgumentException)
		{
			$errors[] = [
				'code' => 'InvalidFilterSettingsScope',
				'parameter' => 'FilterSettings',
				'message' => Loc::getMessage('CRM_DYNAMIC_COMPLEX_ACTIVITY_SCOPE_ERROR'),
			];
		}

		return $errors;
	}

	public static function validateChild(
		$childActivity,
		$bFirstChild = false,
		$childActivityData = [],
	): array
	{
		$errors = parent::validateChild($childActivity, $bFirstChild);
		$scope = static::getDynamicTypeScope();
		if (
			$scope === null
			|| mb_strtolower((string)$childActivity) !== self::CREATE_DYNAMIC_ACTIVITY_TYPE
		)
		{
			return $errors;
		}

		$properties = is_array($childActivityData)
			? ($childActivityData['Properties'] ?? null)
			: null
		;
		$dynamicTypeId = is_array($properties) ? ($properties['DynamicTypeId'] ?? 0) : 0;
		if (\CBPActivity::isExpression($dynamicTypeId))
		{
			return $errors;
		}

		if (!static::createDynamicTypeScopePolicy()->contains($scope, (int)$dynamicTypeId))
		{
			$errors[] = [
				'code' => 'InvalidDynamicTypeScope',
				'parameter' => 'DynamicTypeId',
				'message' => Loc::getMessage('CRM_DYNAMIC_COMPLEX_ACTIVITY_SCOPE_ERROR'),
			];
		}

		return $errors;
	}

	public static function getNodeFilterDynamicTypeScope(): ?DynamicTypeScope
	{
		return static::getDynamicTypeScope();
	}

	public static function getDocumentTypeForNodeAction(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$entityTypeId = static::resolveDynamicEntityTypeId();
		if ($entityTypeId > 0)
		{
			$documentType = \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
			if (is_array($documentType))
			{
				return $documentType;
			}
		}

		return ['crm', Dynamic::class, \CCrmOwnerType::DynamicTypePrefixName . '0'];
	}

	protected static function getDynamicTypeScope(): ?DynamicTypeScope
	{
		return null;
	}

	protected static function createDynamicTypeScopePolicy(): DynamicTypeScopePolicy
	{
		return DynamicTypeScopePolicy::getRequestInstance();
	}

	protected static function resolveDynamicEntityTypeId(): int
	{
		$scope = static::getDynamicTypeScope();
		if ($scope !== null)
		{
			return static::createDynamicTypeScopePolicy()->getRepresentativeEntityTypeId($scope) ?? 0;
		}

		foreach (Container::getInstance()->getDynamicTypesMap()->getTypesCollection() as $type)
		{
			$entityTypeId = (int)$type->getEntityTypeId();
			if ($entityTypeId > 0 && \CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
			{
				return $entityTypeId;
			}
		}

		return 0;
	}
}
