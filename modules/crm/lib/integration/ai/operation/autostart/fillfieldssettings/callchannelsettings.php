<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use CCrmActivityDirection;

final class CallChannelSettings extends BaseChannelSettings
{
	public const CHANNEL_TYPE = 'call';

	private const DEFAULT_CALL_DIRECTION = CCrmActivityDirection::Incoming;

	private bool $autostartTranscriptionOnlyOnFirstCallWithRecording;
	private array $autostartCallDirections;

	public function __construct(
		array $autostartOperationTypes,
		bool $autostartTranscriptionOnlyOnFirstCallWithRecording,
		array $autostartCallDirections
	)
	{
		parent::__construct($autostartOperationTypes);

		$this->autostartTranscriptionOnlyOnFirstCallWithRecording = $autostartTranscriptionOnlyOnFirstCallWithRecording;
		$this->autostartCallDirections = $autostartCallDirections;
	}

	public function getChannelType(): string
	{
		return self::CHANNEL_TYPE;
	}

	public function shouldAutostart(int $operationType, array $context = []): bool
	{
		$callDirection = $context['callDirection'] ?? self::DEFAULT_CALL_DIRECTION;
		$checkAutomaticProcessingParams = $context['checkAutomaticProcessingParams'] ?? true;

		if (
			$checkAutomaticProcessingParams
			&& !(AIManager::isAiCallAutomaticProcessingAllowed() && AIManager::isBaasServiceHasPackage())
		)
		{
			return false;
		}

		$isAllowedOperationType = in_array($operationType, $this->operationTypes, true);
		$isAllowedDirection = in_array($callDirection, $this->autostartCallDirections, true);
		if ($operationType !== TranscribeCallRecording::TYPE_ID)
		{
			return true;
		}

		return $isAllowedOperationType && $isAllowedDirection;
	}

	public function isAutostartTranscriptionOnlyOnFirstCallWithRecording(): bool
	{
		return $this->autostartTranscriptionOnlyOnFirstCallWithRecording
			&& in_array(self::DEFAULT_CALL_DIRECTION, $this->autostartCallDirections, true)
		;
	}

	public function toArray(): array
	{
		return [
			'channelType' => $this->getChannelType(),
			'autostartOperationTypes' => $this->operationTypes,
			'autostartTranscriptionOnlyOnFirstCallWithRecording' => $this->autostartTranscriptionOnlyOnFirstCallWithRecording,
			'autostartCallDirections' => $this->autostartCallDirections,
		];
	}

	public static function fromArray(array $data): ?self
	{
		$types = $data['autostartOperationTypes'] ?? null;
		if (is_array($types))
		{
			$types = (new self([], false, []))->validateOperationTypes($types);
		}

		$autostartTranscriptionOnlyOnFirstCallWithRecording =
			$data['autostartTranscriptionOnlyOnFirstCallWithRecording'] ?? false;

		$autostartCallDirections = $data['autostartCallDirections'] ?? [self::DEFAULT_CALL_DIRECTION];
		if (is_array($autostartCallDirections))
		{
			$validDirections = [CCrmActivityDirection::Incoming, CCrmActivityDirection::Outgoing];
			$autostartCallDirections = array_filter(
				array_map('intval', $autostartCallDirections),
				static fn(int $x) => in_array($x, $validDirections, true)
			);
		}

		if (
			is_array($types)
			&& is_bool($autostartTranscriptionOnlyOnFirstCallWithRecording)
			&& is_array($autostartCallDirections)
		)
		{
			return new self(
				$types,
				$autostartTranscriptionOnlyOnFirstCallWithRecording,
				$autostartCallDirections
			);
		}

		return null;
	}

	public static function getDefault(): self
	{
		return new self(
			[
				SummarizeCallTranscription::TYPE_ID,
				FillItemFieldsFromCallTranscription::TYPE_ID,
			],
			false,
			[self::DEFAULT_CALL_DIRECTION]
		);
	}
}
