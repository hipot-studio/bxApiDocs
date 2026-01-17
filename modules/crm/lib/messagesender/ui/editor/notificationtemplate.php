<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor;

use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Crm\MessageSender\UI\Editor\NotificationTemplate\Placeholder;

final class NotificationTemplate implements \JsonSerializable
{
	/** @var array<string, Placeholder> */
	private array $placeholders = [];

	public function __construct(
		private readonly string $code,
	)
	{
	}

	public function setPlaceholder(Placeholder $placeholder): self
	{
		$this->placeholders[$placeholder->getName()] = $placeholder;

		return $this;
	}

	public function jsonSerialize(): array
	{
		return [
			'code' => $this->code,
			'translation' => NotificationsManager::getTemplateTranslation($this->code),
			'placeholders' => array_values($this->placeholders),
			'signed' => NotificationsManager::signTemplate(
				$this->code,
				$this->getSignablePlaceholders(),
			),
		];
	}

	private function getSignablePlaceholders(): array
	{
		$result = [];
		foreach ($this->placeholders as $placeholder)
		{
			$result[] = [
				'name' => $placeholder->getName(),
				'value' => $placeholder->getValue(),
			];
		}

		return $result;
	}
}
