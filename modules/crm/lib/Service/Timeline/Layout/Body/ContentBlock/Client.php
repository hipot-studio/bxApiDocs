<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

use Bitrix\Crm\Service\Timeline\Layout\Action\Redirect;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Main\PhoneNumber\Parser;
use Bitrix\Main\Web\Uri;
use CCrmEntitySelectorHelper;
use CCrmOwnerType;
use CCrmPerms;
use InvalidArgumentException;

final class Client extends ContentBlock
{
	public const BLOCK_WITH_FORMATTED_VALUE = 1;
	public const BLOCK_WITH_FIXED_TITLE = 2;
	public const BLOCK_WITH_COMMUNICATION_CONTROL = 4;

	private bool $hasFormattedValue;
	private bool $hasFixedTitle;
	private bool $hasCommunicationControl;

	private array $data;
	private ?string $title = null;
	private ?int $userId = null;

	public function __construct(array $data, int $options = 0)
	{
		if (empty($data))
		{
			throw new InvalidArgumentException('Client data cannot be empty');
		}

		// first, setup options
		$this->hasFormattedValue = $options & self::BLOCK_WITH_FORMATTED_VALUE;
		$this->hasFixedTitle = $options & self::BLOCK_WITH_FIXED_TITLE;
		$this->hasCommunicationControl = $options & self::BLOCK_WITH_COMMUNICATION_CONTROL;

		$this->data = $data;
	}

	public function getRendererName(): string
	{
		return 'ClientBlock';
	}

	public function getName(): string
	{
		$name = $this->fetchName();
		$formatted = $this->fetchFormattedValue();

		return trim("$name $formatted");
	}

	public function setTitle(?string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function setUserId(?int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function build(): ?ContentBlock
	{
		$nameBlock = $this->buildNameBlock();
		if (!isset($nameBlock))
		{
			return null;
		}

		$baseBlock = $nameBlock;

		if ($this->hasCommunicationControl)
		{
			$communicationBlock = $this->buildCommunicationBlock();
			if (isset($communicationBlock))
			{
				$baseBlock = (new LineOfTextBlocks())
					->addContentBlock('nameBlock', $nameBlock)
					->addContentBlock('communicationBlock', $communicationBlock)
				;
			}
		}

		return (new ContentBlockWithTitle())
			->setTitle($this->title)
			->setContentBlock($baseBlock)
			->setFixedWidth($this->hasFixedTitle)
			->setInline()
		;
	}

	protected function getProperties(): array
	{
		return [];
	}

	private function buildNameBlock(): null|Link|Text|LineOfTextBlocks
	{
		$clientName = $this->fetchName();
		if (empty($clientName))
		{
			return null;
		}

		$url = isset($this->data['SHOW_URL']) ? new Uri($this->data['SHOW_URL']) : null;
		$action = $url ? new Redirect($url) : null;
		$formattedValue = $this->fetchFormattedValue();
		if (empty($formattedValue))
		{
			$result =  ContentBlockFactory::createTextOrLink($clientName, $action)
				->setTitle($clientName)
				->setIsBold(isset($url))
			;
			if (!$url)
			{
				$result->setColor(Text::COLOR_BASE_90);
			}

			return $result;
		}

		$clientNameBlock = ContentBlockFactory::createTextOrLink($clientName, $action)
			->setTitle($clientName)
			->setIsBold(isset($url))
		;
		if (!$url)
		{
			$clientNameBlock->setColor(Text::COLOR_BASE_90);
		}

		$clientContactBlock = ContentBlockFactory::createTextOrLink($formattedValue, $action);

		return (new LineOfTextBlocks())
			->addContentBlock('clientTitle', $clientNameBlock)
			->addContentBlock('clientContact', $clientContactBlock)
		;
	}

	private function buildCommunicationBlock(): ?ClientCommunication
	{
		$fields = $this->fetchCommunicationFields();
		if (empty($fields))
		{
			return null;
		}

		return new ClientCommunication(
			$fields,
			$this->data['OWNER_TYPE_ID'] ?? 0,
			$this->data['OWNER_ID'] ?? 0,
			$this->data['ENTITY_TYPE_ID'] ?? 0,
			$this->data['ENTITY_ID'] ?? 0
		);
	}

	private function fetchName(): string
	{
		return (string)($this->data['TITLE'] ?? '');
	}

	private function fetchFormattedValue(): string
	{
		if ($this->hasFormattedValue)
		{
			$source = '';
			if (!empty($this->data['SOURCE']))
			{
				$parsed = Parser::getInstance()?->parse($this->data['SOURCE']);
				$source = $parsed ? $parsed->format() : '';
			}

			return empty($this->data['FORMATTED_VALUE'])
				? $source
				: $this->data['FORMATTED_VALUE'];
		}

		return '';
	}

	private function fetchCommunicationFields(): array
	{
		$clientEntityTypeId = $this->data['ENTITY_TYPE_ID'] ?? null;
		$clientEntityId = $this->data['ENTITY_ID'] ?? null;
		if (!isset($clientEntityTypeId, $clientEntityId))
		{
			return [];
		}

		$options = [];
		if (isset($this->userId))
		{
			$options['USER_PERMISSIONS'] = CCrmPerms::GetUserPermissions($this->userId);
		}

		$clientInfo = CCrmEntitySelectorHelper::PrepareEntityInfo(
			CCrmOwnerType::ResolveName($clientEntityTypeId),
			$clientEntityId,
			$options
		);

		return $clientInfo['ADVANCED_INFO']['MULTI_FIELDS'] ?? [];
	}
}
