<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\Bot;

use Bitrix\Im\Bot;
use Bitrix\Im\Model\BotTable;
use Bitrix\Im\V2\Chat\Background\BackgroundId;
use Bitrix\Im\V2\Entity\User\Data\BotData;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\RestEntity;

class BotItem implements RestEntity, PopupDataAggregatable
{
	private int $botId;
	private array $botData;
	private bool $isOwnerFormat;

	public function __construct(int $botId, array $botData, bool $isOwnerFormat = false)
	{
		$this->botId = $botId;
		$this->botData = $botData;
		$this->isOwnerFormat = $isOwnerFormat;
	}

	public static function createFromId(int $botId, bool $isOwnerFormat = false): ?self
	{
		$botData = BotData::getInstance($botId)->toArray();
		if (empty($botData))
		{
			return null;
		}

		return new self($botId, $botData, $isOwnerFormat);
	}

	public static function createFromData(array $botData, bool $isOwnerFormat = false): self
	{
		return new self((int)$botData['BOT_ID'], $botData, $isOwnerFormat);
	}

	public function getId(): int
	{
		return $this->botId;
	}

	public function setOwnerFormat(bool $isOwnerFormat): self
	{
		$this->isOwnerFormat = $isOwnerFormat;

		return $this;
	}

	public static function getRestEntityName(): string
	{
		return 'bot';
	}

	public function toRestFormat(array $option = []): ?array
	{
		if (empty($this->botData))
		{
			return null;
		}

		$type = BotType::fromDbValue($this->botData['TYPE'] ?? Bot::TYPE_BOT);

		$result = [
			'id' => $this->botId,
			'code' => $this->botData['CODE'] ?? '',
			'type' => $type->toRestName(),
			'isHidden' => ($this->botData['HIDDEN'] ?? 'N') === 'Y',
			'isSupportOpenline' => ($this->botData['OPENLINE'] ?? 'N') === 'Y',
			'isReactionsEnabled' => ($this->botData['REACTIONS_ENABLED'] ?? 'N') === 'Y',
			'backgroundId' => BackgroundId::normalize($this->botData['BACKGROUND_ID'] ?? null),
			'language' => Bot::getDefaultLanguage(),
		];

		if ($this->isOwnerFormat)
		{
			$result['moduleId'] = $this->botData['MODULE_ID'] ?? '';
			$result['appId'] = $this->botData['APP_ID'] ?? '';
			$result['eventMode'] = mb_strtolower($this->botData['EVENT_MODE'] ?? Bot::EVENT_MODE_WEBHOOK);
			$result['countMessage'] = (int)($this->botData['COUNT_MESSAGE'] ?? 0);
			$result['countCommand'] = (int)($this->botData['COUNT_COMMAND'] ?? 0);
			$result['countChat'] = (int)($this->botData['COUNT_CHAT'] ?? 0);
			$result['countUser'] = (int)($this->botData['COUNT_USER'] ?? 0);
		}

		return $result;
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		return new PopupData([new UserPopupItem([$this->botId])], $excludedList);
	}
}
