<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler;

use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Main\Grid\Settings;

use Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field\JsFields;
use CBPWorkflowTemplateUser;

class AiAgentsRowAssembler extends RowAssembler
{
	protected Settings $settings;

	public function __construct(array $visibleColumnIds, Settings $settings)
	{
		parent::__construct($visibleColumnIds);
		$this->settings = $settings;
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new JsFields\AgentInfoFieldAssembler(['NAME'], $this->settings),
			new JsFields\UsedByFieldAssembler(['USED_BY'], $this->settings),
			new JsFields\LaunchedByFieldAssembler(['LAUNCHED_BY'], $this->settings),
			new JsFields\LaunchControlFieldAssembler(['LAUNCH_CONTROL'], $this->settings),
			new JsFields\LoadIndicatorFieldAssembler(['LOAD'], $this->settings),
		];
	}

	private function getUserIdFromString(?string $value): ?int
	{
		if (empty($value))
		{
			return null;
		}

		$userId = filter_var($value, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
			],
		]);

		return $userId === false ? null : $userId;
	}

	public function prepareRows(array $rowsList): array
	{
		$rowsList = parent::prepareRows($rowsList);

		$currentUser = new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$currentUserId = $this->getUserIdFromString($currentUser->getId());

		foreach ($rowsList as $key => $row)
		{
			$startedBuUser = false;
			$isSystemTemplate = !empty($row['data']['SYSTEM_CODE']);
			$activatedBy = $this->getUserIdFromString($row['data']['ACTIVATED_BY'] ?? null);

			if (!is_null($activatedBy))
			{
				$startedBuUser = $activatedBy === $currentUserId;
			}

			if (
				$isSystemTemplate
				|| (!$startedBuUser	&& !$currentUser->isAdmin())
			)
			{
				$rowsList[$key]['editable'] = false;
			}
		}

		return $rowsList;
	}
}
