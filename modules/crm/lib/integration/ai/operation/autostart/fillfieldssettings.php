<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\CallChannelSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChannelSettingsFactory;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChannelSettingsInterface;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChatChannelSettings;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;
use JsonSerializable;

final class FillFieldsSettings implements AutoStartInterface, JsonSerializable
{
	private array $channelSettings;

	public function __construct(array $channelSettings = [])
	{
		$this->channelSettings = $channelSettings;
	}

	public function addChannelSettings(ChannelSettingsInterface $settings): void
	{
		$this->channelSettings[$settings->getChannelType()] = $settings;
	}

	public function getChannelSettings(string $channelType): ?ChannelSettingsInterface
	{
		return $this->channelSettings[$channelType] ?? null;
	}

	public function shouldAutostart(
		int $operationType,
		int $callDirection,
		bool $checkAutomaticProcessingParams = true
	): bool
	{
		if (
			$checkAutomaticProcessingParams
			&& !(AIManager::isAiCallAutomaticProcessingAllowed() && AIManager::isBaasServiceHasPackage())
		)
		{
			return false;
		}

		// backwards compatibility for calls
		$callSettings = $this->getChannelSettings(CallChannelSettings::CHANNEL_TYPE);
		if ($callSettings === null)
		{
			return false;
		}

		return $callSettings->shouldAutostart($operationType, [
			'callDirection' => $callDirection,
			'checkAutomaticProcessingParams' => $checkAutomaticProcessingParams,
		]);
	}

	public function isAutostartTranscriptionOnlyOnFirstCallWithRecording(): bool
	{
		// backwards compatibility for calls
		$callSettings = $this->getChannelSettings(CallChannelSettings::CHANNEL_TYPE);
		if ($callSettings instanceof CallChannelSettings)
		{
			return $callSettings->isAutostartTranscriptionOnlyOnFirstCallWithRecording();
		}

		return false;
	}

	public function jsonSerialize(): array
	{
		$result = array_map(static function ($settings) {
			return $settings->toArray();
		}, $this->channelSettings);

		return [
			'channels' => $result,
		];
	}

	public static function fromJson(array $json): ?self
	{
		// backwards compatibility for calls
		if (!isset($json['channels']))
		{
			$callSettings = CallChannelSettings::fromArray($json);
			if ($callSettings !== null)
			{
				return new self([
					CallChannelSettings::CHANNEL_TYPE => $callSettings,
					ChatChannelSettings::CHANNEL_TYPE => ChatChannelSettings::getDefault(),
				]);
			}

			return null;
		}

		// new format
		$channelSettings = [];
		foreach ($json['channels'] as $channelType => $data)
		{
			$settings = ChannelSettingsFactory::create($channelType, $data);
			if ($settings !== null)
			{
				$channelSettings[$channelType] = $settings;
			}
		}

		return new self($channelSettings);
	}

	public static function getDefault(): self
	{
		return new self([
			CallChannelSettings::CHANNEL_TYPE => CallChannelSettings::getDefault(),
			ChatChannelSettings::CHANNEL_TYPE => ChatChannelSettings::getDefault(),
		]);
	}

	public static function get(int $entityTypeId, ?int $categoryId = null): self
	{
		$settingsRaw = Option::get('crm', self::getOptionName($entityTypeId, $categoryId));
		if ($settingsRaw === '')
		{
			return self::getDefault();
		}

		try
		{
			$settingsJson = Json::decode($settingsRaw);
		}
		catch (ArgumentException)
		{
			$settingsJson = [];
		}

		$settings = self::fromJson($settingsJson);

		return $settings instanceof self ? $settings : self::getDefault();
	}

	public static function save(self $settings, int $entityTypeId, ?int $categoryId = null): Result
	{
		$result = new Result();

		if (!CCrmOwnerType::IsDefined($entityTypeId))
		{
			return $result->addError(new Error('Unknown entityTypeId', ErrorCode::INVALID_ARG_VALUE));
		}

		Option::set(
			'crm',
			self::getOptionName($entityTypeId, $categoryId),
			Json::encode($settings->jsonSerialize()),
		);

		return $result;
	}

	public static function checkSavePermissions(int $entityTypeId, ?int $categoryId = null, ?int $userId = null): bool
	{
		return self::checkReadPermissions($entityTypeId, $categoryId, $userId);
	}

	public static function checkReadPermissions(int $entityTypeId, ?int $categoryId = null, ?int $userId = null): bool
	{
		$userPermissions = Container::getInstance()->getUserPermissions($userId)->entityType();
		return (is_null($categoryId))
			? $userPermissions->canUpdateItems($entityTypeId)
			: $userPermissions->canUpdateItemsInCategory($entityTypeId, $categoryId)
		;
	}

	private static function getOptionName(int $entityTypeId, ?int $categoryId): string
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory?->isCategoriesSupported() && $categoryId === null)
		{
			$categoryId = $factory?->createDefaultCategoryIfNotExist()->getId();
		}

		$typeKey = (string)($entityTypeId);
		if ($categoryId !== null)
		{
			$typeKey .= "_$categoryId";
		}

		return "ai_autostart_settings_$typeKey";
	}
}
