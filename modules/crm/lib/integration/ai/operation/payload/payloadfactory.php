<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\GenerateCallCriteria;
use Bitrix\Crm\Integration\AI\Operation\GenerateCallScriptDescription;
use Bitrix\Crm\Integration\AI\Operation\GenerateCallScriptFromDialog;
use Bitrix\Crm\Integration\AI\Operation\GenerateClientSummary;
use Bitrix\Crm\Integration\AI\Operation\GenerateManagerSummary;
use Bitrix\Crm\Integration\AI\Operation\GroupSuspiciousCalls;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\CallCriteriaGenerator;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\CallGrouping;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\CallScoring;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\CallScoringSelectScript;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\CallScoringV2;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\CallScriptCreator;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\CallScriptDescriptionGenerator;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\ClientDialogueActionExtraction;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\ClientSummary;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\ExtractFormFields;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\ManagerSummary;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\RepeatSalesPrompt;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\RepeatSalesScreeningItem;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\ScoringCriteriaExtraction;
use Bitrix\Crm\Integration\AI\Operation\Payload\Payload\SummarizeTranscript;
use Bitrix\Crm\Integration\AI\Operation\Sandbox;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem;
use Bitrix\Crm\Integration\AI\Operation\SelectCallScoreScript;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\ArgumentException;

final class PayloadFactory
{
	public static function build(int $code, ?int $userId, ItemIdentifier $identifier): PayloadInterface
	{
		$v2FeatureEnabled = Feature::enabled(Feature\CallScoringV2::class);

		$throwException = static fn() => throw new ArgumentException('Unsupported operation code');

		return match ($code)
		{
			SummarizeCallTranscription::TYPE_ID => new SummarizeTranscript($userId, $identifier),
			FillItemFieldsFromCallTranscription::TYPE_ID => new ExtractFormFields($userId, $identifier),
			ScoreCall::TYPE_ID => new CallScoring($userId, $identifier),
			ScoreCallV2::TYPE_ID => new CallScoringV2($userId, $identifier),
			ExtractScoringCriteria::TYPE_ID => new ScoringCriteriaExtraction($userId, $identifier),
			SelectCallScoreScript::TYPE_ID => $v2FeatureEnabled ? new CallScoringSelectScript($userId, $identifier) : $throwException(),
			GenerateCallCriteria::TYPE_ID => $v2FeatureEnabled ? new CallCriteriaGenerator($userId, $identifier) : $throwException(),
			GenerateCallScriptFromDialog::TYPE_ID => $v2FeatureEnabled ? new CallScriptCreator($userId, $identifier) : $throwException(),
			GenerateCallScriptDescription::TYPE_ID => $v2FeatureEnabled ? new CallScriptDescriptionGenerator($userId, $identifier) : $throwException(),
			GroupSuspiciousCalls::TYPE_ID => $v2FeatureEnabled ? new CallGrouping($userId, $identifier) : $throwException(),
			GenerateManagerSummary::TYPE_ID => new ManagerSummary($userId, $identifier),
			FillRepeatSaleTips::TYPE_ID, Sandbox\FillRepeatSaleTips::TYPE_ID => new RepeatSalesPrompt($userId, $identifier),
			ScreeningRepeatSaleItem::TYPE_ID => new RepeatSalesScreeningItem($userId, $identifier),
			AnalyzeCommunication::TYPE_ID => new ClientDialogueActionExtraction($userId, $identifier),
			GenerateClientSummary::TYPE_ID => new ClientSummary($userId, $identifier),
			default => $throwException(),
		};
	}
}
