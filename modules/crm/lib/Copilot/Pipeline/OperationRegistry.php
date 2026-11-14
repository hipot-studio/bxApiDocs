<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Integration\AI\Operation\AbstractOperation;
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
use Bitrix\Crm\Integration\AI\Operation\Sandbox;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem;
use Bitrix\Crm\Integration\AI\Operation\SelectCallScoreScript;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class OperationRegistry
{
	/** @var array<int, class-string<AbstractOperation>> */
	private array $map;

	public function __construct()
	{
		$this->map = [
			TranscribeCallRecording::TYPE_ID => TranscribeCallRecording::class,
			SummarizeCallTranscription::TYPE_ID => SummarizeCallTranscription::class,
			FillItemFieldsFromCallTranscription::TYPE_ID => FillItemFieldsFromCallTranscription::class,
			ScoreCall::TYPE_ID => ScoreCall::class,
			ScoreCallV2::TYPE_ID => ScoreCallV2::class,
			SelectCallScoreScript::TYPE_ID => SelectCallScoreScript::class,
			ExtractScoringCriteria::TYPE_ID => ExtractScoringCriteria::class,
			GenerateCallCriteria::TYPE_ID => GenerateCallCriteria::class,
			GenerateCallScriptFromDialog::TYPE_ID => GenerateCallScriptFromDialog::class,
			GenerateCallScriptDescription::TYPE_ID => GenerateCallScriptDescription::class,
			GroupSuspiciousCalls::TYPE_ID => GroupSuspiciousCalls::class,
			GenerateManagerSummary::TYPE_ID => GenerateManagerSummary::class,
			FillRepeatSaleTips::TYPE_ID => FillRepeatSaleTips::class,
			ScreeningRepeatSaleItem::TYPE_ID => ScreeningRepeatSaleItem::class,
			Sandbox\FillRepeatSaleTips::TYPE_ID => Sandbox\FillRepeatSaleTips::class,
			AnalyzeCommunication::TYPE_ID => AnalyzeCommunication::class,
			GenerateClientSummary::TYPE_ID => GenerateClientSummary::class,
		];
	}

	/**
	 * @return class-string<AbstractOperation>|null
	 */
	public function getByTypeId(int $typeId): ?string
	{
		return $this->map[$typeId] ?? null;
	}

	/**
	 * @return array<int, class-string<AbstractOperation>>
	 */
	public function getMap(): array
	{
		return $this->map;
	}
}
