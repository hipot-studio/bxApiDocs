<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\V2ScriptDataLoader;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\BaasManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;

if (!Main\Loader::includeModule('crm'))
{
	return;
}

class CCrmCopilotCallAssessmentDetailsComponent extends Base
{
	public function executeComponent(): void
	{
		if (
			!AIManager::isAiCallProcessingEnabled()
			|| !Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canRead())
		{
			$this->showError(
				'CRM_COPILOT_CALL_ASSESSMENT_DETAILS_ACCESS_DENIED_MSGVER_1',
				'CRM_COPILOT_CALL_ASSESSMENT_DETAILS_ACCESS_DENIED_DESCRIPTION',
			);

			return;
		}

		$v2Data = null;
		$isPendingGeneration = false;

		$id = (int)($this->arParams['ID'] ?? 0);
		if ($id)
		{
			$callAssessmentItem = CopilotCallAssessmentController::getInstance()->getById($id);
			if (!$callAssessmentItem)
			{
				$this->showError('CRM_COPILOT_CALL_ASSESSMENT_DETAILS_NOT_FOUND');

				return;
			}

			$this->setTitle($callAssessmentItem->getTitle());

			$request = Application::getInstance()->getContext()->getRequest();
			$this->arResult['isCopy'] = $request->get('copy') === 'Y';
			$this->arResult['data'] = CallAssessmentItem::createFromEntity($callAssessmentItem)->toArray();

			$controlData = $this->arResult['data']['controlData'] ?? [];
			$headItems = $controlData['headItems'] ?? [];
			$userIds = [];
			foreach ($headItems as $headItem)
			{
				$headType = $headItem[0] ?? null;
				if ($headType === 'user')
				{
					$userIds[] = $headItem[1];
				}
			}

			$this->arResult['data']['users'] = Container::getInstance()->getUserBroker()->getBunchByIds($userIds);

			$availabilityData = $this->arResult['data']['availabilityData'] ?? [];
			array_walk($availabilityData,
				static function (&$row) {
					$row['startPoint'] = substr($row['startPoint']->toString(), 0, -3);
					$row['endPoint'] = substr($row['endPoint']->toString(), 0, -3);
				},
			);
			$this->arResult['data']['availabilityData'] = $availabilityData;

			if ($this->isCallScoringV2Enabled())
			{
				$v2Data = (new V2ScriptDataLoader())->loadById($id);
				$isPendingGeneration = $callAssessmentItem->getStatus() === CallAssessmentItem::STATUS_GENERATING_FROM_DIALOG;
			}
		}
		else
		{
			$defaultTitle = (string)Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_DETAILS_TITLE');
			$this->setTitle($defaultTitle);

			if ($this->isCallScoringV2Enabled())
			{
				$this->arResult['data'] = CallAssessmentItem::createFromArray([])->toArray();
				$v2Data = (new V2ScriptDataLoader())->buildEmpty($defaultTitle);
			}
			else
			{
				$this->arResult['data'] = CallAssessmentItem::createFromArray([
					'title' => $defaultTitle,
				])->toArray();
			}
		}

		if (is_array($v2Data))
		{
			$this->arResult['data']['criteria'] = $v2Data['criteria'];
			$this->arResult['data']['filters'] = $v2Data['filters'];
			$this->arResult['data']['isAiImprovementEnabled'] = $v2Data['isAiImprovementEnabled'];
			$this->arResult['data']['prompt'] = $v2Data['prompt'];
			$this->arResult['data']['isHintEnabled'] = $v2Data['isHintEnabled'];
			$this->arResult['data']['hasHint'] = $v2Data['hasHint'];
			$this->arResult['data']['updatedAt'] = $v2Data['updatedAt'] ?? null;
			$this->arResult['data']['processedCallsCount'] = $v2Data['processedCallsCount'] ?? 0;
			$this->arResult['data']['isGeneratedByCopilot'] = $v2Data['isGeneratedByCopilot'] ?? false;
		}

		$this->arResult['isPendingGeneration'] = $isPendingGeneration;
		$this->arResult['copilotSettings'] = $this->getCopilotSettings();
		$this->arResult['baasSettings'] = BaasManager::getSettings();
		$this->arResult['readOnly'] =
			!Container::getInstance()->getUserPermissions()->copilotCallAssessment()->canEdit()
			|| !AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment)
		;
		$this->arResult['isEnabled'] = AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment);

		$page = $this->isCallScoringV2Enabled() ? 'callscoringv2' : '';
		$this->includeComponentTemplate($page);
	}

	private function setTitle(string $title): void
	{
		$this->getApplication()->setTitle(htmlspecialcharsbx($title));
	}

	private function showError(string $messageCode, string $descriptionCode = ''): void
	{
		$this->getApplication()->IncludeComponent(
			'bitrix:ui.info.error',
			'',
			[
				'TITLE' => Loc::getMessage($messageCode),
				'DESCRIPTION' => empty($descriptionCode) ? '' : Loc::getMessage($descriptionCode),
			],
		);
	}

	private function getCopilotSettings(): array
	{
		if (!AIManager::isEnabledInGlobalSettings(EventHandler::SETTINGS_FILL_CRM_TEXT_ENABLED_CODE))
		{
			return [];
		}

		return [
			'moduleId' => 'crm',
			'contextId' => 'crm_call_assessment_settings_prompt_' . CurrentUser::get()->getId(),
			'category' => Bitrix\AI\SharePrompt\Enums\Category::CRM_COMMENT_FIELD->value,
			'autoHide' => true,
		];
	}

	protected function getToolbarParameters(): array
	{
		$parameters = parent::getToolbarParameters();

		$parameters['isWithFavoriteStar'] = false;

		if (!$this->isCallScoringV2Enabled())
		{
			$parameters['underTitleHtml'] = '<div class="copilot-call-assessment-pagetitle-description">' . Loc::getMessage('CRM_COPILOT_CALL_ASSESSMENT_DETAILS_SUBTITLE') . '</div>';
			$parameters['isEditableTitle'] = true;
		}

		return $parameters;
	}

	private function isCallScoringV2Enabled(): bool
	{
		return AIManager::isCallScoringV2Enabled();
	}
}
