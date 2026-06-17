<?php

namespace Bitrix\BIConnector\Superset\Dashboard\UrlParameter;

use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

final class ScopeMap
{
	public const GLOBAL_SCOPE = 'global_scope';
	/**
	 * @return Parameter[]
	 */
	public static function getAvailableParameters(): array
	{
		return [
			Parameter::CurrentUser,
			Parameter::WorkflowTemplateId,
			Parameter::TasksFlowsFlowId,
		];
	}

	/**
	 * @return Parameter[]
	 */
	public static function getGlobals(): array
	{
		return [
			Parameter::CurrentUser,
		];
	}

	/**
	 * @return Parameter[][]
	 */
	public static function getMap(): array
	{
		return [
			ScopeService::BIC_SCOPE_WORKFLOW_TEMPLATE => [
				Parameter::WorkflowTemplateId,
			],
			ScopeService::BIC_SCOPE_TASKS_FLOWS_FLOW => [
				Parameter::TasksFlowsFlowId,
			],
		];
	}

	/**
	 * @param string $scopeCode
	 *
	 * @return Parameter[]|null
	 */
	public static function getScopeParameters(string $scopeCode): ?array
	{
		return self::getMap()[$scopeCode] ?? null;
	}

	/**
	 * @param Parameter $parameter
	 *
	 * @return string[]
	 */
	public static function getParameterScopeCodes(Parameter $parameter): array
	{
		if (in_array($parameter, self::getGlobals(), true))
		{
			return [self::GLOBAL_SCOPE];
		}

		$scopes = [];
		foreach (self::getMap() as $scopeCode => $parameters)
		{
			if (in_array($parameter, $parameters, true))
			{
				$scopes[] = $scopeCode;
			}
		}

		return $scopes;
	}

	public static function getRequiredParamList(): array
	{
		return [
			Parameter::CurrentUser->code() => Loc::getMessage('BI_CONNECTOR_DASHBOARD_SCOPE_REQUIRED_PARAMETER_USER_ID_HINT'),
		];
	}

	public static function loadGlobalValue(Parameter $code, ?int $userId = null): mixed
	{
		if ($code === Parameter::CurrentUser)
		{
			return $userId ?? (int)CurrentUser::get()->getId();
		}

		return null;
	}

	public static function isParameterValueAllowed(Parameter $code, int $userId, int $valueId): bool
	{
		if ($valueId <= 0)
		{
			return false;
		}

		if ($code === Parameter::TasksFlowsFlowId)
		{
			if (!Loader::includeModule('tasks'))
			{
				return false;
			}

			$row = \Bitrix\Tasks\Flow\Internal\FlowTable::getList([
				'select' => ['ID'],
				'filter' => ['=ID' => $valueId, '=ACTIVE' => 1],
				'limit' => 1,
			])->fetch();
			if (!$row)
			{
				return false;
			}

			$controller = new \Bitrix\Tasks\Flow\Access\FlowAccessController($userId);

			return $controller->checkByItemId(\Bitrix\Tasks\Flow\Access\FlowAction::READ, $valueId);
		}

		if ($code === Parameter::WorkflowTemplateId)
		{
			if (!Loader::includeModule('bizproc'))
			{
				return false;
			}

			$template = \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable::getList([
				'select' => ['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'],
				'filter' => ['=ID' => $valueId, '=ACTIVE' => 'Y', '!=MODULE_ID' => 'tasks'],
				'limit' => 1,
			])->fetchObject();
			if (!$template)
			{
				return false;
			}

			try
			{
				return \CBPDocument::canUserOperateDocumentType(
					\CBPCanUserOperateOperation::StartWorkflow,
					$userId,
					$template->getDocumentComplexType(),
				);
			}
			catch (\Throwable $e)
			{
				AiToolsLogger::logErrors(
					[new Error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine())],
					[
						'stage' => 'scope_map.workflow_template_acl_single',
						'template_id' => $valueId,
						'user_id' => $userId,
					],
				);

				return false;
			}
		}

		return false;
	}

	/**
	 * Resolve scope-level url parameter values accessible to a user, for the
	 * AI assistant to present as options. Returns [] for globals.
	 *
	 * TODO: biconnector reaches into tasks/bizproc internals here — promote to
	 * native list tools (`tasks/list_flows`, `bizproc/list_workflow_templates`)
	 * once the cross-module work is unblocked.
	 *
	 * @return array<array{id:int|string, name:string}>
	 */
	public static function listParameterValues(Parameter $code, int $userId): array
	{
		if ($code === Parameter::TasksFlowsFlowId)
		{
			if (!Loader::includeModule('tasks'))
			{
				return [];
			}

			$rows = \Bitrix\Tasks\Flow\Internal\FlowTable::getList([
				'select' => ['ID', 'NAME'],
				'filter' => ['=ACTIVE' => 1],
				'order' => ['NAME' => 'ASC'],
				'limit' => 200,
			])->fetchAll();

			$controller = new \Bitrix\Tasks\Flow\Access\FlowAccessController($userId);
			$result = [];
			foreach ($rows as $row)
			{
				if (!$controller->checkByItemId(\Bitrix\Tasks\Flow\Access\FlowAction::READ, (int)$row['ID']))
				{
					continue;
				}
				$result[] = [
					'id' => (int)$row['ID'],
					'name' => (string)$row['NAME'],
				];
			}

			return $result;
		}

		if ($code === Parameter::WorkflowTemplateId)
		{
			if (!Loader::includeModule('bizproc'))
			{
				return [];
			}

			$query = \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable::getList([
				'select' => ['ID', 'NAME', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'],
				'filter' => ['=ACTIVE' => 'Y', '!=MODULE_ID' => 'tasks'],
				'order' => ['NAME' => 'ASC'],
				'limit' => 200,
			]);

			$result = [];
			while ($template = $query->fetchObject())
			{
				try
				{
					$canStart = \CBPDocument::canUserOperateDocumentType(
						\CBPCanUserOperateOperation::StartWorkflow,
						$userId,
						$template->getDocumentComplexType(),
					);
				}
				catch (\Throwable $e)
				{
					AiToolsLogger::logErrors(
						[new Error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine())],
						[
							'stage' => 'scope_map.workflow_template_acl',
							'template_id' => (int)$template->getId(),
							'user_id' => $userId,
						],
					);
					$canStart = false;
				}

				if (!$canStart)
				{
					continue;
				}
				$result[] = [
					'id' => (int)$template->getId(),
					'name' => (string)$template->getName(),
				];
			}

			return $result;
		}

		return [];
	}

	public static function getParamList(): array
	{
		return array_merge(
			ScopeMap::getGlobalParams(),
			ScopeMap::getScopeParams(),
		);
	}

	private static function getGlobalParams(): array
	{
		$globalParamsMap = [];

		$globalParams = ScopeMap::getGlobals();
		$globalScopeKey = 'global';
		foreach ($globalParams as $globalParam)
		{
			$globalParamsMap[$globalParam->code()] = [
				'code' => $globalParam->code(),
				'title' => $globalParam->title(),
				'description' => $globalParam->description(),
				'scope' => $globalScopeKey,
				'superTitle' => Loc::getMessage('BI_CONNECTOR_DASHBOARD_SCOPE_MAP_GLOBAL_PARAMETER_TITLE'),
			];
		}

		return $globalParamsMap;
	}

	private static function getScopeParams(): array
	{
		$scopeParamList = [];

		$map = ScopeMap::getMap();
		foreach ($map as $scopeCode => $scopeParams)
		{
			foreach ($scopeParams as $scopeParam)
			{
				$scopeParamList[$scopeParam->code()] = [
					'code' => $scopeParam->code(),
					'title' => $scopeParam->title(),
					'description' => $scopeParam->description(),
					'scope' => $scopeCode,
					'superTitle' => ScopeService::getInstance()->getScopeName($scopeCode),
				];
			}
		}

		return $scopeParamList;
	}
}
