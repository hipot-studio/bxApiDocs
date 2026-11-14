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
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\CallCriteriaGenerator;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\CallGrouping;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\CallScoring;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\CallScoringSelectScript;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\CallScoringV2;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\CallScriptCreator;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\CallScriptDescriptionGenerator;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\CallTranscribe;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\ClientDialogueActionExtraction;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\ClientSummary;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\ExtractFormFields;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\ManagerSummary;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\RepeatSalesPrompt;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\RepeatSalesScreeningItem;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\ScoringCriteriaExtraction;
use Bitrix\Crm\Integration\AI\Operation\Payload\Stub\SummarizeTranscript;
use Bitrix\Crm\Integration\AI\Operation\Sandbox;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem;
use Bitrix\Crm\Integration\AI\Operation\SelectCallScoreScript;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\ArgumentException;

final class StubFactory
{
	public static function build(int $code, ItemIdentifier $identifier): StubInterface
	{
		$v2FeatureEnabled = Feature::enabled(Feature\CallScoringV2::class);
		$throwException = static fn() => throw new ArgumentException('Unsupported operation code');

		return match ($code)
		{
			TranscribeCallRecording::TYPE_ID => new CallTranscribe(),
			SummarizeCallTranscription::TYPE_ID => new SummarizeTranscript(),
			FillItemFieldsFromCallTranscription::TYPE_ID => new ExtractFormFields($identifier),
			ScoreCall::TYPE_ID => new CallScoring(),
			ScoreCallV2::TYPE_ID => new CallScoringV2(),
			ExtractScoringCriteria::TYPE_ID => new ScoringCriteriaExtraction(),
			SelectCallScoreScript::TYPE_ID => $v2FeatureEnabled ? new CallScoringSelectScript() : $throwException(),
			GenerateCallCriteria::TYPE_ID => $v2FeatureEnabled ? new CallCriteriaGenerator() : $throwException(),
			GenerateCallScriptFromDialog::TYPE_ID => $v2FeatureEnabled ? new CallScriptCreator() : $throwException(),
			GenerateCallScriptDescription::TYPE_ID => $v2FeatureEnabled ? new CallScriptDescriptionGenerator() : $throwException(),
			GroupSuspiciousCalls::TYPE_ID => $v2FeatureEnabled ? new CallGrouping() : $throwException(),
			GenerateManagerSummary::TYPE_ID => new ManagerSummary(),
			FillRepeatSaleTips::TYPE_ID, Sandbox\FillRepeatSaleTips::TYPE_ID => new RepeatSalesPrompt(),
			ScreeningRepeatSaleItem::TYPE_ID => new RepeatSalesScreeningItem(),
			AnalyzeCommunication::TYPE_ID => new ClientDialogueActionExtraction(),
			GenerateClientSummary::TYPE_ID => new ClientSummary(),
			default => $throwException(),
		};
	}
}
