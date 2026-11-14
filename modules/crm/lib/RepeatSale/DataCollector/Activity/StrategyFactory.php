<?php

namespace Bitrix\Crm\RepeatSale\DataCollector\Activity;

use Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy\CallRecordingStrategy;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy\CommentsStrategy;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy\EmailsStrategy;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy\OpenLinesStrategy;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\Strategy\TodosStrategy;

class StrategyFactory
{
	private readonly TextNormalizer $textNormalizer;
	private readonly ActivityQueryBuilder $queryBuilder;

	/**
	 * @param TextLengthConfig|null $lengthConfig The lengths the texts of the strategies are kept inside
	 * of, when a caller needs other ones than the collector of the repeat sales does.
	 */
	public function __construct(?TextLengthConfig $lengthConfig = null)
	{
		$this->textNormalizer = new TextNormalizer($lengthConfig ?? new TextLengthConfig());
		$this->queryBuilder = new ActivityQueryBuilder();
	}

	public function createCallRecordingStrategy(int $entityTypeId): CallRecordingStrategy
	{
		return new CallRecordingStrategy($entityTypeId, $this->textNormalizer, $this->queryBuilder);
	}

	public function createCommentsStrategy(int $entityTypeId): CommentsStrategy
	{
		return new CommentsStrategy($entityTypeId, $this->textNormalizer, $this->queryBuilder);
	}

	public function createTodosStrategy(int $entityTypeId): TodosStrategy
	{
		return new TodosStrategy($entityTypeId, $this->textNormalizer, $this->queryBuilder);
	}

	public function createEmailsStrategy(int $entityTypeId): EmailsStrategy
	{
		return new EmailsStrategy($entityTypeId, $this->textNormalizer, $this->queryBuilder);
	}

	public function createOpenLinesStrategy(int $entityTypeId): OpenLinesStrategy
	{
		return new OpenLinesStrategy($entityTypeId, $this->textNormalizer, $this->queryBuilder);
	}
}
