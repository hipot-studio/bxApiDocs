<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Integration\AI\Operation\AbstractOperation;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Sandbox;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem;
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
			ExtractScoringCriteria::TYPE_ID => ExtractScoringCriteria::class,
			FillRepeatSaleTips::TYPE_ID => FillRepeatSaleTips::class,
			ScreeningRepeatSaleItem::TYPE_ID => ScreeningRepeatSaleItem::class,
			Sandbox\FillRepeatSaleTips::TYPE_ID => Sandbox\FillRepeatSaleTips::class,
			AnalyzeCommunication::TYPE_ID => AnalyzeCommunication::class,
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
