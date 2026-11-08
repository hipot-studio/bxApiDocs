<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScope;
use Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScopePolicy;
use Bitrix\Crm\Relation\RelationManager;
use Bitrix\Crm\Service\Container;

class CBPCrmGetRelationDataActivity extends CBPActivity implements IBPConfigurableActivity
{
	private const TARGET_ENTITY_TYPE_ID = 'TargetEntityTypeId';
	private const SOURCE_DOCUMENT_PROPERTIES = 'SourceDocumentProperties';
	private const SOURCE_DOCUMENT = 'SourceDocument';
	private const RELATION_TARGET_FIELDS = 'RelationTargetFields';
	private const RELATION_SOURCE_DOCUMENT = 'RelationSourceDocument';

	/** Control name of the service binding field of the create activity the package fills in. */
	private const RELATION_SOURCE_DOCUMENT_FIELD_ID = 'relation_source_document';

	private const RELATION_PACKAGE_SETTING = 'relationPackage';
	private const RELATION_PACKAGE_ROLE_SOURCE_DOCUMENT = 'sourceDocument';
	private const RELATION_PACKAGE_ROLE_TARGET_FIELD = 'targetField';

	private const FILTER_SETTINGS_PROPERTY = 'FilterSettings';
	private const SMART_INVOICE_HOST_NODE_TYPE = 'crmsmartinvoicecomplexactivity';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::TARGET_ENTITY_TYPE_ID => null,
			self::SOURCE_DOCUMENT_PROPERTIES => null,
			self::RELATION_TARGET_FIELDS => null,

			self::SOURCE_DOCUMENT => null,
			self::RELATION_SOURCE_DOCUMENT => null,
		];
	}

	protected function ReInitialize()
	{
		parent::ReInitialize();

		$this->{self::SOURCE_DOCUMENT} = null;
		$this->{self::RELATION_SOURCE_DOCUMENT} = null;
	}

	public function Execute()
	{
		if (!CModule::IncludeModule('crm'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$documentId = $this->GetDocumentId();
		if (!is_array($documentId) || !$documentId)
		{
			$this->WriteToTrackingService(GetMessage('CRM_GRDA_EMPTY_DOCUMENT'), 0, CBPTrackingType::Error);

			return CBPActivityExecutionStatus::Closed;
		}

		if (($documentId[0] ?? '') !== 'crm')
		{
			$this->WriteToTrackingService(GetMessage('CRM_GRDA_INCORRECT_DOCUMENT'), 0, CBPTrackingType::Error);

			return CBPActivityExecutionStatus::Closed;
		}

		$this->SetProperties([
			self::SOURCE_DOCUMENT => $documentId,
			self::RELATION_SOURCE_DOCUMENT => $documentId,
		]);

		return CBPActivityExecutionStatus::Closed;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::TARGET_ENTITY_TYPE_ID => [
				'Name' => GetMessage('CRM_GRDA_TARGET_ENTITY_TYPE'),
				'FieldName' => 'target_entity_type_id',
				'Type' => FieldType::SELECT,
				'Required' => true,
				'Options' => static::getTargetEntityOptions($documentType, $context),
				'AllowSelection' => false,
				'Setter' => static::getTargetEntityTypePropertySetter($documentType, $context),
			],
		];
	}

	/**
	 * The unified form submits the target type only, while the declared additional results also need the
	 * maps of the relation package, so this field owns their writes as well.
	 */
	private static function getTargetEntityTypePropertySetter(array $documentType, array $context): Closure
	{
		return static fn($value): array => self::buildActivityProperties($documentType, $context, $value);
	}

	private static function buildActivityProperties(array $documentType, array $context, $targetEntityTypeId): array
	{
		$properties = [
			self::TARGET_ENTITY_TYPE_ID => $targetEntityTypeId,
		];

		$targetEntityTypeId = (int)$targetEntityTypeId;
		if ($targetEntityTypeId <= 0 || !CModule::IncludeModule('crm'))
		{
			return $properties;
		}

		$targetDocumentType = \CCrmBizProcHelper::ResolveDocumentType($targetEntityTypeId);
		if (!is_array($targetDocumentType))
		{
			return $properties;
		}

		$sourceDocumentTypes = self::resolveSourceDocumentTypes($documentType, $context);
		$properties[self::SOURCE_DOCUMENT_PROPERTIES] = self::buildSourceDocumentProperties(
			$sourceDocumentTypes[0] ?? self::resolveWorkflowDocumentType($documentType, $context),
			$sourceDocumentTypes,
		);
		$properties[self::RELATION_TARGET_FIELDS] = self::buildRelationTargetFields($targetDocumentType);

		return $properties;
	}

	private static function buildSourceDocumentProperties(
		array $sourceDocumentType,
		array $sourceDocumentTypes = [],
	): array
	{
		$package = ['role' => self::RELATION_PACKAGE_ROLE_SOURCE_DOCUMENT];
		if (count($sourceDocumentTypes) > 1)
		{
			$package['sourceDocumentTypes'] = $sourceDocumentTypes;
		}

		return [
			self::SOURCE_DOCUMENT => [
				'Name' => GetMessage('CRM_GRDA_SOURCE_DOCUMENT') ?: self::SOURCE_DOCUMENT,
				'Type' => FieldType::DOCUMENT,
				'Multiple' => false,
				'Default' => array_values($sourceDocumentType),
				'Settings' => [
					self::RELATION_PACKAGE_SETTING => $package,
				],
			],
		];
	}

	/**
	 * The package publishes no business field: the only target field is the service binding field, and its
	 * value is the reference to the source of the relation.
	 */
	private static function buildRelationTargetFields(array $targetDocumentType): array
	{
		return [
			self::RELATION_SOURCE_DOCUMENT => [
				'Name' => GetMessage('CRM_GRDA_RELATION_SOURCE_DOCUMENT') ?: self::RELATION_SOURCE_DOCUMENT,
				'Type' => FieldType::DOCUMENT,
				'Multiple' => false,
				'Settings' => [
					self::RELATION_PACKAGE_SETTING => [
						'role' => self::RELATION_PACKAGE_ROLE_TARGET_FIELD,
						'fieldId' => self::RELATION_SOURCE_DOCUMENT_FIELD_ID,
						'targetDocumentType' => array_values($targetDocumentType),
					],
				],
			],
		];
	}

	/**
	 * Targets are the types the child relations of every source type of the host node agree on: the node
	 * runs with one of its filters, and the relation has to exist whatever the outcome is.
	 */
	private static function getTargetEntityOptions(array $documentType, array $context): array
	{
		if (!CModule::IncludeModule('crm'))
		{
			return [];
		}

		$sourceEntityTypeIds = static::resolveSourceEntityTypeIds($documentType, $context);
		if (!$sourceEntityTypeIds)
		{
			return [];
		}

		$isSmartInvoiceHost = static::isSmartInvoiceHost($context);
		$scope = static::resolveHostNodeDynamicTypeScope($context);
		$scopePolicy = static::createDynamicTypeScopePolicy();

		$options = [];
		foreach (static::resolveBoundEntityTypeIds($sourceEntityTypeIds) as $targetEntityTypeId)
		{
			if (!\CCrmOwnerType::isPossibleDynamicTypeId($targetEntityTypeId))
			{
				continue;
			}

			if (
				$isSmartInvoiceHost
				&& !$scopePolicy->contains(DynamicTypeScope::Crm, $targetEntityTypeId)
				&& !$scopePolicy->contains(DynamicTypeScope::AutomatedSolution, $targetEntityTypeId)
			)
			{
				continue;
			}

			// An unknown host node leaves the scope unknown as well, and the graph of the source is then
			// the whole of what narrows the list.
			if (!$isSmartInvoiceHost && $scope !== null && !$scopePolicy->contains($scope, $targetEntityTypeId))
			{
				continue;
			}

			$options[$targetEntityTypeId] = \CCrmOwnerType::GetDescription($targetEntityTypeId);
		}

		return $options;
	}

	/**
	 * Types bound as a child to every one of the source types.
	 */
	private static function resolveBoundEntityTypeIds(array $sourceEntityTypeIds): array
	{
		$relationManager = static::createRelationManager();

		$boundEntityTypeIds = null;
		foreach ($sourceEntityTypeIds as $sourceEntityTypeId)
		{
			$childEntityTypeIds = [];
			foreach ($relationManager->getChildRelations($sourceEntityTypeId) as $relation)
			{
				$childEntityTypeIds[] = $relation->getChildEntityTypeId();
			}

			$boundEntityTypeIds = $boundEntityTypeIds === null
				? $childEntityTypeIds
				: array_intersect($boundEntityTypeIds, $childEntityTypeIds);
		}

		return array_unique($boundEntityTypeIds ?? []);
	}

	/**
	 * Actual source types of the host node: the types of its filters, or the type of the template document
	 * when it carries no filter. A type the node cannot publish as a smart process is no source at all, so
	 * the representative type of the node never takes part here.
	 */
	private static function resolveSourceEntityTypeIds(array $documentType, array $context): array
	{
		if (static::isSmartInvoiceHost($context))
		{
			return [\CCrmOwnerType::SmartInvoice];
		}

		$entityTypeIds = self::extractFilterEntityTypeIds(self::resolveHostNode($context));
		if (!$entityTypeIds)
		{
			$templateEntityTypeId = self::resolveDocumentEntityTypeId(
				self::resolveWorkflowDocumentType($documentType, $context),
			);
			$entityTypeIds = $templateEntityTypeId > 0 ? [$templateEntityTypeId] : [];
		}

		return array_values(
			array_filter(
				$entityTypeIds,
				static fn(int $entityTypeId): bool => \CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId),
			),
		);
	}

	private static function extractFilterEntityTypeIds(?array $hostNode): array
	{
		$filterSettings = $hostNode['Properties'][self::FILTER_SETTINGS_PROPERTY] ?? null;
		if (!is_array($filterSettings))
		{
			return [];
		}

		$entityTypeIds = [];
		foreach ($filterSettings as $filter)
		{
			if (!is_array($filter))
			{
				continue;
			}

			// Both keys are accepted by the node filter resolver, so both name a filter type here.
			$entityTypeId = (int)($filter['targetEntityTypeId'] ?? $filter['entityTypeId'] ?? 0);
			if ($entityTypeId > 0)
			{
				$entityTypeIds[$entityTypeId] = $entityTypeId;
			}
		}

		return array_values($entityTypeIds);
	}

	private static function resolveSourceDocumentTypes(array $documentType, array $context): array
	{
		$sourceDocumentTypes = [];
		foreach (self::resolveSourceEntityTypeIds($documentType, $context) as $sourceEntityTypeId)
		{
			$sourceDocumentType = \CCrmBizProcHelper::ResolveDocumentType($sourceEntityTypeId);
			if (is_array($sourceDocumentType))
			{
				$sourceDocumentTypes[] = array_values($sourceDocumentType);
			}
		}

		return $sourceDocumentTypes;
	}

	private static function resolveWorkflowDocumentType(array $documentType, array $context): array
	{
		if (!array_key_exists('WorkflowDocumentType', $context))
		{
			return $documentType;
		}

		$workflowDocumentType = $context['WorkflowDocumentType'];
		if (!is_array($workflowDocumentType))
		{
			return [];
		}

		$workflowDocumentType = array_values($workflowDocumentType);
		if (
			count($workflowDocumentType) !== 3
			|| $workflowDocumentType[0] !== 'crm'
			|| !is_string($workflowDocumentType[1])
			|| !is_string($workflowDocumentType[2])
		)
		{
			return [];
		}

		$entityTypeId = self::resolveDocumentEntityTypeId($workflowDocumentType);
		$resolvedDocumentType = \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);

		return is_array($resolvedDocumentType) && array_values($resolvedDocumentType) === $workflowDocumentType
			? $workflowDocumentType
			: [];
	}

	private static function resolveHostNodeDynamicTypeScope(array $context): ?DynamicTypeScope
	{
		$hostNodeType = self::resolveHostNode($context)['Type'] ?? null;

		return is_string($hostNodeType) ? DynamicTypeScope::fromActivityType($hostNodeType) : null;
	}

	private static function isSmartInvoiceHost(array $context): bool
	{
		$hostNodeType = self::resolveHostNode($context)['Type'] ?? null;
		if (!is_string($hostNodeType))
		{
			return false;
		}

		$normalizedType = strtolower(trim($hostNodeType));
		if (str_starts_with($normalizedType, 'cbp'))
		{
			$normalizedType = substr($normalizedType, 3);
		}

		return $normalizedType === self::SMART_INVOICE_HOST_NODE_TYPE;
	}

	protected static function createRelationManager(): RelationManager
	{
		return Container::getInstance()->getRelationManager();
	}

	protected static function createDynamicTypeScopePolicy(): DynamicTypeScopePolicy
	{
		return DynamicTypeScopePolicy::getRequestInstance();
	}

	/**
	 * Host node of the action: the editor puts the node being edited first on the canvas it wraps into a
	 * root activity. A slice the editor never sent names no node.
	 */
	private static function resolveHostNode(array $context): ?array
	{
		$slice = $context['Template'] ?? null;

		return is_array($slice) ? self::findFirstCanvasNode($slice) : null;
	}

	private static function findFirstCanvasNode(array $level): ?array
	{
		foreach ($level as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			if (($node['Type'] ?? '') !== NodesToTemplate::ROOT_NODE_TYPE)
			{
				return $node;
			}

			$children = $node['Children'] ?? null;
			$hostNode = is_array($children) ? self::findFirstCanvasNode($children) : null;
			if ($hostNode !== null)
			{
				return $hostNode;
			}
		}

		return null;
	}

	private static function resolveDocumentEntityTypeId(array $documentType): int
	{
		return isset($documentType[2]) ? (int)\CCrmOwnerType::ResolveID($documentType[2]) : 0;
	}

	public static function ValidateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];

		if ((int)($arTestProperties[self::TARGET_ENTITY_TYPE_ID] ?? 0) <= 0)
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => self::TARGET_ENTITY_TYPE_ID,
				'message' => GetMessage('CRM_GRDA_EMPTY_TARGET'),
			];
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}
}
