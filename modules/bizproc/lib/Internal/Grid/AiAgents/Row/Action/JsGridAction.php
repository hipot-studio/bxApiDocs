<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Action;

use Bitrix\Bizproc\Internal\Grid\AiAgents\Settings\AiAgentsSettings;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Web\Json;
use CBPWorkflowTemplateUser;

abstract class JsGridAction extends BaseAction
{
	private string $actionId;
	private string $extensionName;
	private string $gridId;

	public function __construct(AiAgentsSettings $settings)
	{
		$this->actionId = static::getId();
		$this->extensionName = $settings->getExtensionName();
		$this->gridId = $settings->getID();
	}

	abstract protected function isEnabled(array $rawFields): bool;
	abstract protected function getActionParams(array $rawFields): array;

	protected function getActionOptions(): array
	{
		return [];
	}

	protected function isUserAdmin(): bool
	{
		return (new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->isAdmin();
	}

	protected function isStartedByCurrentUser(int|string|null $userIdStartedBy): bool
	{
		if (is_null($userIdStartedBy))
		{
			return false;
		}

		$userIdStartedBy = filter_var($userIdStartedBy, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
			],
		]);

		if (!$userIdStartedBy)
		{
			return false;
		}

		$currentUserId = (new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser))->getId();

		if ($currentUserId > 0)
		{
			return $currentUserId === $userIdStartedBy;
		}

		return false;
	}


	public function getControl(array $rawFields): ?array
	{
		$extension = $this->extensionName;
		$gridId = $this->gridId;
		$actionOptions = $this->getActionOptions();
		$actionParams = $this->getActionParams($rawFields);
		$params = Json::encode([
			'actionId' => $this->actionId,
			'options' => $actionOptions,
			'params' => $actionParams,
		]);

		$this->onclick = sprintf("BX.%s.GridManager.getInstance('%s').runAction(%s)", $extension, $gridId, $params);

		$control = parent::getControl($rawFields);

		if (isset($control) && !$this->isEnabled($rawFields))
		{
			$disabledClass = 'menu-popup-item-disabled menu-popup-no-icon';
			$control['className'] =
				isset($control['className'])
					? $control['className'] . ' ' . $disabledClass
					: $disabledClass
			;

			unset($control['ONCLICK']);
		}

		return $control;
	}
}