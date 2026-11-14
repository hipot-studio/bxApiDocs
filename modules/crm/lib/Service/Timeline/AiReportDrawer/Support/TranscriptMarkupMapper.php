<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

final class TranscriptMarkupMapper
{
	public static function map(array $payload): array
	{
		$transcriptVersion = $payload['transcriptVersion'] ?? null;

		return [
			'segments' => $payload['segments'] ?? null,
			'speakerSource' => $payload['speakerSource'] ?? null,
			'canSeparateSpeakers' => is_int($transcriptVersion) && $transcriptVersion >= 1,
		];
	}
}
