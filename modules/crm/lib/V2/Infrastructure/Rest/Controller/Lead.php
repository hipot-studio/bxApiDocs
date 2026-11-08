<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\RestV3CrudDeal;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Main\Engine\Action;
use Bitrix\Rest\V3\Attribute\McpTool;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\FeatureNotAvailableOnCurrentPlanException;
use Bitrix\Rest\V3\Exception\MethodNotFoundException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

final class Lead extends RestController
{
	#[McpTool(toolSetCode: 'crm_rest_v3_lead', descriptionOverride: 'Check whether leads are enabled in CRM.')]
	public function isEnabledAction(): ArrayResponse
	{
		return $this->makeResponse();
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_lead', descriptionOverride: 'Enable leads in CRM.')]
	public function enableAction(): ArrayResponse
	{
		if (!RestrictionManager::getLeadsRestriction()->hasPermission())
		{
			throw new FeatureNotAvailableOnCurrentPlanException();
		}

		$this->setLeadMode(true);

		return $this->makeResponse();
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_lead', descriptionOverride: 'Disable leads in CRM.')]
	public function disableAction(): ArrayResponse
	{
		$this->setLeadMode(false);

		return $this->makeResponse();
	}

	protected function processBeforeAction(Action $action): bool
	{
		if (!parent::processBeforeAction($action))
		{
			return false;
		}

		if (!Feature::enabled(RestV3CrudDeal::class))
		{
			throw new MethodNotFoundException('crm.lead.' . $action->getName());
		}

		if (!Container::getInstance()->getUserPermissions((int)$this->getCurrentUser()->getId())->isCrmAdmin())
		{
			throw new AccessDeniedException();
		}

		return true;
	}

	private function makeResponse(): ArrayResponse
	{
		return new ArrayResponse(['enabled' => LeadSettings::isEnabled()]);
	}

	private function setLeadMode(bool $enabled): void
	{
		LeadSettings::enableLead($enabled);
		\Bitrix\Main\Config\Option::set('crm', 'crm_lead_enabled_show', 'N');
	}
}
