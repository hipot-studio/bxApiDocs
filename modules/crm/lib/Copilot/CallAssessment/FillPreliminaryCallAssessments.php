<?php

namespace Bitrix\Crm\Copilot\CallAssessment;

use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentCriteriaController;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AutoCheckType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\CallScoringV2;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

final class FillPreliminaryCallAssessments
{
	private const AUTO_CHECK_TYPE_ID = AutoCheckType::FIRST_INCOMING;
	private const V2_CRITERIA_COUNT = 5;
	private const SHIPPED_REFERENCE_LANGUAGE_IDS = ['de', 'en', 'kz', 'ru'];
	private static array $referencePromptsByCode = [];
	private static array $referenceMessagesByLanguageId = [];

	public static function isWaiting(): bool
	{
		return Option::get('crm', 'waiting_call_assessment_rules_filling', 'N') === 'Y';
	}

	public static function getReferencePromptByCode(string $code): ?string
	{
		if ($code === '')
		{
			return null;
		}

		$message = (string)Loc::getMessage('FPCA_' . strtoupper($code) . '_PROMPT');

		return $message === '' ? null : $message;
	}

	/**
	 * @return array<string, string>
	 */
	public static function getReferencePromptsByCode(string $code): array
	{
		if ($code === '')
		{
			return [];
		}
		if (isset(self::$referencePromptsByCode[$code]))
		{
			return self::$referencePromptsByCode[$code];
		}

		$key = 'FPCA_' . strtoupper($code) . '_PROMPT';
		$references = [];
		foreach (self::SHIPPED_REFERENCE_LANGUAGE_IDS as $languageId)
		{
			$messages = self::$referenceMessagesByLanguageId[$languageId]
				??= Loc::loadLanguageFile(__FILE__, $languageId)
			;
			$message = (string)($messages[$key] ?? '');
			if ($message !== '')
			{
				$references[$languageId] = $message;
			}
		}

		self::$referencePromptsByCode[$code] = $references;

		return self::$referencePromptsByCode[$code];
	}

	public static function getReferenceDescriptionByCode(string $code): ?string
	{
		if ($code === '')
		{
			return null;
		}

		$message = (string)Loc::getMessage('FPCA_' . strtoupper($code) . '_DESCRIPTION');

		return $message === '' ? null : $message;
	}

	public function execute(): void
	{
		$controller = CopilotCallAssessmentController::getInstance();

		$collection = $controller->getList();
		if ($collection->isEmpty())
		{
			if (Feature::enabled(CallScoringV2::class))
			{
				$this->fillV2($controller);
			}
			else
			{
				foreach ($this->getV1Data() as $data)
				{
					$callAssessment = CallAssessmentItem::createFromArray($data);
					$controller->add($callAssessment);
				}
			}
		}

		Option::delete('crm', ['name' => 'waiting_call_assessment_rules_filling']);
	}

	private function fillV2(CopilotCallAssessmentController $controller): void
	{
		$data = $this->getPoliteCommunicationAssessment();
		$data['prompt'] = '';
		$data['isHintEnabled'] = false;
		$callAssessment = CallAssessmentItem::createFromArray($data);
		$result = $controller->add($callAssessment);
		if (!$result->isSuccess())
		{
			return;
		}

		$assessmentId = (int)$result->getId();
		$criteriaController = CopilotCallAssessmentCriteriaController::getInstance();

		$sort = 100;
		foreach ($this->getPoliteCommunicationCriteria() as $criterion)
		{
			$criteriaController->add([
				'ASSESSMENT_ID' => $assessmentId,
				'TITLE' => $criterion['title'],
				'DESCRIPTION' => $criterion['description'],
				'SORT' => $sort,
			]);
			$sort += 100;
		}
	}

	private function getPoliteCommunicationAssessment(): array
	{
		return [
			'title' => Loc::getMessage('FPCA_POLITE_COMMUNICATION_TITLE'),
			'description' => Loc::getMessage('FPCA_POLITE_COMMUNICATION_DESCRIPTION'),
			'prompt' => Loc::getMessage('FPCA_POLITE_COMMUNICATION_PROMPT'),
			'gist' => Loc::getMessage('FPCA_POLITE_COMMUNICATION_GIST'),
			'clientTypeIds' => [ClientType::ANY->value],
			'callTypeId' => CallType::ALL->value,
			'isEnabled' => true,
			'autoCheckTypeId' => self::AUTO_CHECK_TYPE_ID->value,
			'jobId' => 0,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
			'code' => 'polite_communication',
			'lowBorder' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'highBorder' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];
	}

	/**
	 * @return array<int, array{title: string, description: string}>
	 */
	private function getPoliteCommunicationCriteria(): array
	{
		$criteria = [];
		for ($i = 1; $i <= self::V2_CRITERIA_COUNT; $i++)
		{
			$title = Loc::getMessage("FPCA_POLITE_COMMUNICATION_CRITERIA_{$i}_TITLE");
			$description = Loc::getMessage("FPCA_POLITE_COMMUNICATION_CRITERIA_{$i}_DESCRIPTION");
			if ($title === '' || $description === '')
			{
				continue;
			}

			$criteria[] = [
				'title' => $title,
				'description' => $description,
			];
		}

		return $criteria;
	}

	private function getV1Data(): array
	{
		return [
			$this->getFirstImpressionAssessments(),
			$this->getDealSupportAssessments(),
			$this->getCommunicationEtiquetteAssessments(),
			$this->getObjectionManagementAssessments(),
			$this->getComplaintHandlingAssessments(),
			$this->getPresentationOfNewAssessments(),
			$this->getIncreaseLoyaltyAssessments(),
			$this->getSpecialOfferAssessments(),
		];
	}

	private function getFirstImpressionAssessments(): array
	{
		return [
			'title' => Loc::getMessage('FPCA_FIRST_IMPRESSION_TITLE'),
			'description' => Loc::getMessage('FPCA_FIRST_IMPRESSION_DESCRIPTION'),
			'prompt' => Loc::getMessage('FPCA_FIRST_IMPRESSION_PROMPT'),
			'gist' => Loc::getMessage('FPCA_FIRST_IMPRESSION_GIST'),
			'clientTypeIds' => [ClientType::NEW->value],
			'callTypeId' => CallType::ALL->value,
			'isEnabled' => true,
			'autoCheckTypeId' => self::AUTO_CHECK_TYPE_ID->value,
			'jobId' => 0,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
			'code' => 'first_impression',
			'low_border' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'high_border' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];
	}

	private function getDealSupportAssessments(): array
	{
		return [
			'title' => Loc::getMessage('FPCA_DEAL_SUPPORT_TITLE'),
			'description' => Loc::getMessage('FPCA_DEAL_SUPPORT_DESCRIPTION'),
			'prompt' => Loc::getMessage('FPCA_DEAL_SUPPORT_PROMPT'),
			'gist' => Loc::getMessage('FPCA_DEAL_SUPPORT_GIST'),
			'clientTypeIds' => [ClientType::IN_WORK->value],
			'callTypeId' => CallType::ALL->value,
			'isEnabled' => true,
			'autoCheckTypeId' => self::AUTO_CHECK_TYPE_ID->value,
			'jobId' => 0,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
			'code' => 'deal_support',
			'low_border' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'high_border' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];
	}

	private function getCommunicationEtiquetteAssessments(): array
	{
		return [
			'title' => Loc::getMessage('FPCA_COMMUNICATION_ETIQUETTE_TITLE'),
			'description' => Loc::getMessage('FPCA_COMMUNICATION_ETIQUETTE_DESCRIPTION'),
			'prompt' => Loc::getMessage('FPCA_COMMUNICATION_ETIQUETTE_PROMPT'),
			'gist' => Loc::getMessage('FPCA_COMMUNICATION_ETIQUETTE_GIST'),
			'clientTypeIds' => $this->getAllClientTypeIds(),
			'callTypeId' => CallType::ALL->value,
			'isEnabled' => true,
			'autoCheckTypeId' => self::AUTO_CHECK_TYPE_ID->value,
			'jobId' => 0,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
			'code' => 'communication_etiquette',
			'low_border' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'high_border' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];
	}

	private function getObjectionManagementAssessments(): array
	{
		return [
			'title' => Loc::getMessage('FPCA_OBJECTION_MANAGEMENT_TITLE'),
			'description' => Loc::getMessage('FPCA_OBJECTION_MANAGEMENT_DESCRIPTION'),
			'prompt' => Loc::getMessage('FPCA_OBJECTION_MANAGEMENT_PROMPT'),
			'gist' => Loc::getMessage('FPCA_OBJECTION_MANAGEMENT_GIST'),
			'clientTypeIds' => $this->getAllClientTypeIds(),
			'callTypeId' => CallType::ALL->value,
			'isEnabled' => true,
			'autoCheckTypeId' => self::AUTO_CHECK_TYPE_ID->value,
			'jobId' => 0,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
			'code' => 'objection_management',
			'low_border' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'high_border' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];
	}

	private function getComplaintHandlingAssessments(): array
	{
		return [
			'title' => Loc::getMessage('FPCA_COMPLAINT_HANDLING_TITLE'),
			'description' => Loc::getMessage('FPCA_COMPLAINT_HANDLING_DESCRIPTION'),
			'prompt' => Loc::getMessage('FPCA_COMPLAINT_HANDLING_PROMPT'),
			'gist' => Loc::getMessage('FPCA_COMPLAINT_HANDLING_GIST'),
			'clientTypeIds' => $this->getAllClientTypeIds(),
			'callTypeId' => CallType::ALL->value,
			'isEnabled' => true,
			'autoCheckTypeId' => self::AUTO_CHECK_TYPE_ID->value,
			'jobId' => 0,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
			'code' => 'complaint_handling',
			'low_border' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'high_border' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];
	}

	private function getPresentationOfNewAssessments(): array
	{
		return [
			'title' => Loc::getMessage('FPCA_PRESENTATION_OF_NEW_TITLE'),
			'description' => Loc::getMessage('FPCA_PRESENTATION_OF_NEW_DESCRIPTION'),
			'prompt' => Loc::getMessage('FPCA_PRESENTATION_OF_NEW_PROMPT'),
			'gist' => Loc::getMessage('FPCA_PRESENTATION_OF_NEW_GIST'),
			'clientTypeIds' => $this->getAllClientTypeIds(),
			'callTypeId' => CallType::OUTGOING->value,
			'isEnabled' => true,
			'autoCheckTypeId' => self::AUTO_CHECK_TYPE_ID->value,
			'jobId' => 0,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
			'code' => 'presentation_of_new',
			'low_border' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'high_border' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];
	}

	private function getIncreaseLoyaltyAssessments(): array
	{
		return [
			'title' => Loc::getMessage('FPCA_INCREASE_LOYALTY_TITLE'),
			'description' => Loc::getMessage('FPCA_INCREASE_LOYALTY_DESCRIPTION'),
			'prompt' => Loc::getMessage('FPCA_INCREASE_LOYALTY_PROMPT'),
			'gist' => Loc::getMessage('FPCA_INCREASE_LOYALTY_GIST'),
			'clientTypeIds' => [ClientType::RETURN_CUSTOMER->value],
			'callTypeId' => CallType::ALL->value,
			'isEnabled' => true,
			'autoCheckTypeId' => self::AUTO_CHECK_TYPE_ID->value,
			'jobId' => 0,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
			'code' => 'increase_loyalty',
			'low_border' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'high_border' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];
	}

	private function getSpecialOfferAssessments(): array
	{
		return [
			'title' => Loc::getMessage('FPCA_SPECIAL_OFFER_TITLE'),
			'description' => Loc::getMessage('FPCA_SPECIAL_OFFER_DESCRIPTION'),
			'prompt' => Loc::getMessage('FPCA_SPECIAL_OFFER_PROMPT'),
			'gist' => Loc::getMessage('FPCA_SPECIAL_OFFER_GIST'),
			'clientTypeIds' => $this->getAllClientTypeIds(),
			'callTypeId' => CallType::ALL->value,
			'isEnabled' => true,
			'autoCheckTypeId' => self::AUTO_CHECK_TYPE_ID->value,
			'jobId' => 0,
			'status' => QueueTable::EXECUTION_STATUS_SUCCESS,
			'code' => 'special_offer',
			'low_border' => CallAssessmentItem::LOW_BORDER_DEFAULT,
			'high_border' => CallAssessmentItem::HIGH_BORDER_DEFAULT,
		];
	}

	/**
	 * @return int[]
	 */
	private function getAllClientTypeIds(): array
	{
		return [
			ClientType::NEW->value,
			ClientType::IN_WORK->value,
			ClientType::REPEATED_APPROACH->value,
			ClientType::RETURN_CUSTOMER->value,
		];
	}
}
