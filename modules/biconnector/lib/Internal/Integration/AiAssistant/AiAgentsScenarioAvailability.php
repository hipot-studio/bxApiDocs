<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

/**
 * Computes whether the AI agents launch scenario is available to the current user.
 *
 * Mirrors the check order of BIConstructorAccess (tariff -> tool -> access right) and adds
 * a readiness check on top of it, without reusing the action-filter itself as a predicate.
 */
final class AiAgentsScenarioAvailability
{
	public const STATE_READY = 'ready';
	public const STATE_TARIFF = 'tariff';
	public const STATE_UNAVAILABLE = 'unavailable';
	public const STATE_PREPARING = 'preparing';

	public const RESTRICTION_CODE_TARIFF = 'limit_crm_BI_constructor';

	/**
	 * @return array{available: bool, state: string, restrictionCode?: string}
	 */
	public static function check(): array
	{
		if (!Feature::isBuilderEnabled())
		{
			return [
				'available' => false,
				'state' => self::STATE_TARIFF,
				'restrictionCode' => self::RESTRICTION_CODE_TARIFF,
			];
		}

		if (Loader::includeModule('intranet') && !ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi'))
		{
			return [
				'available' => false,
				'state' => self::STATE_UNAVAILABLE,
			];
		}

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return [
				'available' => false,
				'state' => self::STATE_UNAVAILABLE,
			];
		}

		if (!DomainLinkService::getInstance()->isLinked())
		{
			return [
				'available' => false,
				'state' => self::STATE_PREPARING,
			];
		}

		if (!SupersetInitializer::isSupersetReady())
		{
			return [
				'available' => false,
				'state' => self::STATE_PREPARING,
			];
		}

		return [
			'available' => true,
			'state' => self::STATE_READY,
		];
	}
}
