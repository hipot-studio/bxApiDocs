<?php

namespace Bitrix\Crm\RepeatSale\DataCollector;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;
use Bitrix\Crm\Timeline\Entity\TimelineTable;
use Bitrix\Crm\Timeline\TimelineType;
use Bitrix\Main\Entity\Query\Join;
use Bitrix\Main\Entity\ReferenceField;
use CCrmContentType;

final class ActivityDataCollector implements CopilotMarkerProviderInterface
{
	private const DEFAULT_LIMIT = 5;

	private const TYPE_CALL_RECORDING_TRANSCRIPTS = 'call_recording_transcripts';
	private const TYPE_COMMENTS = 'comments';
	private const TYPE_TODOS = 'todos';

	private const LENGTH_LIMITS = [
		self::TYPE_CALL_RECORDING_TRANSCRIPTS => [
			'min_length' => 100,
			'max_length' => 20000,
		],
		self::TYPE_COMMENTS => [
			'min_length' => 100,
			'max_length' => 5000,
		],
		self::TYPE_TODOS => [
			'min_length' => 100,
			'max_length' => 5000,
		],
	];

	public function __construct(private readonly int $entityTypeId)
	{}

	public function getMarkers(array $parameters = []): array
	{
		$entityId = (int)($parameters['entityId'] ?? 0);
		if ($entityId <= 0)
		{
			return [];
		}

		$result = [];
		$transcripts = $this->getCallRecordingTranscripts($entityId);
		if (!empty($transcripts))
		{
			$result[self::TYPE_CALL_RECORDING_TRANSCRIPTS] = $transcripts;
		}

		$comments = $this->getComments($entityId);
		if (!empty($comments))
		{
			$result[self::TYPE_COMMENTS] = $comments;
		}

		$todos = $this->getTodos($entityId);
		if (!empty($todos))
		{
			$result[self::TYPE_TODOS] = $todos;
		}

		return $result;
	}

	private function getCallRecordingTranscripts(int $entityId): array
	{
		$callActivityIds = ActivityTable::query()
			->setSelect(['ID'])
			->where('OWNER_TYPE_ID',$this->entityTypeId)
			->where('OWNER_ID', $entityId)
			->where('PROVIDER_ID', Call::ACTIVITY_PROVIDER_ID)
			->whereNotNull('DESCRIPTION')
			->setOrder(['CREATED' => 'DESC'])
			->setLimit(self::DEFAULT_LIMIT)
			->fetchAll()
		;
		if (empty($callActivityIds))
		{
			return [];
		}

		$jobRepository = JobRepository::getInstance();
		$transcripts = array_map(
			function (array $row) use ($jobRepository)
			{
				$activityId = (int)($row['ID'] ?? 0);
				$transcription = $jobRepository
					->getTranscribeCallRecordingResultByActivity($activityId)
					?->getPayload()
					?->transcription
				;

				return $this->normalizeText(
					$transcription ?? '',
					self::LENGTH_LIMITS[self::TYPE_CALL_RECORDING_TRANSCRIPTS]
				);
			},
			$callActivityIds
		);

		return array_values(array_filter($transcripts));
	}

	private function getComments(int $entityId): array
	{
		$commentsRaw = TimelineTable::query()
			->setSelect(['COMMENT'])
			->registerRuntimeField(
				'',
				new ReferenceField(
					'BIND',
					TimelineBindingTable::getEntity(),
					['=this.ID' => 'ref.OWNER_ID'],
					['join_type' => Join::TYPE_LEFT]
				)
			)
			->where('TYPE_ID', TimelineType::COMMENT)
			->where('BIND.ENTITY_TYPE_ID', $this->entityTypeId)
			->where('BIND.ENTITY_ID', $entityId)
			->setOrder(['CREATED' => 'DESC'])
			->setLimit(self::DEFAULT_LIMIT)
			->fetchAll()
		;

		$comments = array_map(
			fn(array $row) => $this->normalizeText(
				$row['COMMENT'] ?? '',
				self::LENGTH_LIMITS[self::TYPE_COMMENTS]
			),
			$commentsRaw
		);

		return array_values(array_filter($comments));
	}

	private function getTodos(int $entityId): array
	{
		$todosRaw = ActivityTable::query()
			->setSelect(['SUBJECT', 'DESCRIPTION'])
			->where('OWNER_TYPE_ID',$this->entityTypeId)
			->where('OWNER_ID', $entityId)
			->where('PROVIDER_ID', ToDo::PROVIDER_ID)
			->whereNotNull('DESCRIPTION')
			->setOrder(['CREATED' => 'DESC'])
			->setLimit(self::DEFAULT_LIMIT)
			->fetchAll()
		;

		$todos = array_map(
			fn(array $row) => $this->normalizeText(
				sprintf('%s: %s', $row['SUBJECT'] ?? '', $row['DESCRIPTION'] ?? ''),
				self::LENGTH_LIMITS[self::TYPE_TODOS]
			),
			$todosRaw
		);

		return array_values(array_filter($todos));
	}

	private function normalizeText(string $text, array $config): ?string
	{
		$text = trim($text);
		if (empty($text))
		{
			return null;
		}

		$minChars = $config['min_length'];
		$maxChars = $config['max_length'];

		$text = TextHelper::cleanTextByType($text, CCrmContentType::BBCode);
		$text = trim(str_replace('&nbsp;', '', $text));
		$length = mb_strlen($text, 'UTF-8');

		if ($length < $minChars)
		{
			return null;
		}

		if ($length > $maxChars)
		{
			$text = mb_substr($text, 0, $maxChars, 'UTF-8');
		}

		return $text;
	}
}
