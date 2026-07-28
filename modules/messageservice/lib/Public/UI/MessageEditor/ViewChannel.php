<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor;

use Bitrix\MessageService\Public\UI\MessageEditor\Channel\From;
use Bitrix\MessageService\Public\UI\MessageEditor\Channel\To;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Backend;
use Bitrix\MessageService\Sender\Base;

/**
 * A view model for Channel for editor
 */
final readonly class ViewChannel implements \JsonSerializable
{
	public function __construct(
		private string $id,
		private Backend $backend,
		private Appearance $appearance,
		private array $fromList = [],
		private array $toList = [],
		private bool $isConnected,
		private bool $isPromo = false,
	)
	{
	}

	public static function fromSender(
		string $id,
		Base $sender,
		string $senderCode,
		Appearance $appearance,
		bool $isConnected,
		?array $fromList = null,
		bool $isPromo = false,
	): self
	{
		return new self(
			$id,
			new Backend(
				$senderCode,
				(string)$sender->getId(),
				(string)$sender->getName(),
				(string)$sender->getShortName(),
				$sender->isConfigurable() && $sender->isTemplatesBased(),
			),
			$appearance,
			$fromList ?? self::collectFromListForSender($sender),
			[],
			$isConnected,
			$isPromo,
		);
	}

	/**
	 * @return From[]
	 */
	public static function collectFromListForSender(Base $sender): array
	{
		$fromList = [];

		$defaultFrom = $sender->getDefaultFrom();
		foreach ($sender->getFromList() as $fromInfo)
		{
			if (!is_array($fromInfo))
			{
				continue;
			}

			$fromInfo['isDefault'] = isset($fromInfo['id']) && $fromInfo['id'] === $defaultFrom;
			$from = From::fromArray($fromInfo);
			if ($from !== null)
			{
				$fromList[] = $from;
			}
		}

		return $fromList;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getAppearance(): Appearance
	{
		return $this->appearance;
	}

	public function isPromo(): bool
	{
		return $this->isPromo;
	}

	/**
	 * Returns a list of 'from' correspondents for the channel. It can be a subset of backend's list for UI reasons.
	 *
	 * @return From[]
	 */
	public function getFromList(): array
	{
		return $this->fromList;
	}

	/**
	 * @return To[]
	 */
	public function getToList(): array
	{
		return $this->toList;
	}

	public function isConnected(): bool
	{
		return $this->isConnected && !empty($this->fromList);
	}

	public function getBackend(): Backend
	{
		return $this->backend;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'backend' => $this->backend,
			'appearance' => $this->appearance,
			'fromList' => $this->getFromList(),
			'toList' => $this->getToList(),
			'isConnected' => $this->isConnected(),
			'isPromo' => $this->isPromo,
			'isTemplatesBased' => $this->backend->isTemplatesBased(),
		];
	}
}
