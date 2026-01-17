<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\BizprocDesigner\Internal\Entity\Block;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\BlockCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\ConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Collection\PortCollection;
use Bitrix\BizprocDesigner\Internal\Entity\Connection;
use Bitrix\BizprocDesigner\Internal\Entity\NodeType;
use Bitrix\BizprocDesigner\Internal\Entity\Port;
use Bitrix\BizprocDesigner\Internal\Enum\PortDirection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\Draft;
use Bitrix\Main\Security\Random;

class AiAssistantDraftConverterService
{
	private const BLOCK_WIDTH = 200;
	private const BLOCK_HEIGHT = 50;
	private const TARGET_PORT_ID = 'i0';
	private const SOURCE_PORT_ID = 'o0';
	private const PORT_POSITION = 1;
	private const START_POSITION_X = 300;
	private const START_POSITION_Y = 50;
	private const BLOCK_MARGIN = 100;
	private const TITLE_PROPERTY_NAME = 'Title';
	private string $uniqueBlockSalt;
	public function __construct()
	{
		$this->uniqueBlockSalt = Random::getStringByAlphabet(
			8,
			Random::ALPHABET_ALPHALOWER | Random::ALPHABET_ALPHAUPPER,
		);
	}

	public function covertFromAgentInput(
		int $draftId,
		int $templateId,
		int $userId,
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
	): Draft
	{
		return new Draft(
			draftId: $draftId,
			templateId: $templateId,
			userId: $userId,
			blocks: $this->makeBlocks($blocks),
			connections: $this->makeConnections($connections),
		);
	}

	private function makeBlocks(AgentBlockCollection $blocks): BlockCollection
	{
		$collection = new BlockCollection();
		$x = self::START_POSITION_X;
		foreach ($blocks as $block)
		{
			$collection->add(
				new Block(
					id: $this->makeActivityId($block->id),
					type: $this->makeNodeType($block->type),
					x: $x,
					y: self::START_POSITION_Y,
					width: self::BLOCK_WIDTH,
					height: self::BLOCK_HEIGHT,
					title: $block->title,
					ports: $this->makePortsByBlock($block),
					activityData: new ActivityData(
						name: $this->makeActivityId($block->id),
						type:  $block->type,
						properties: $this->makeActivityProperties($block),
					)
				)
			);

			$x += self::BLOCK_WIDTH + self::BLOCK_MARGIN;
		}

		return $collection;
	}

	private function makePorts(bool $hasInput, bool $hasOutput): PortCollection
	{
		$ports = new PortCollection();

		if ($hasInput)
		{
			$ports->add(
				new Port(
					id: self::TARGET_PORT_ID,
					direction: PortDirection::Input,
					position: self::PORT_POSITION,
				)
			);
		}

		if ($hasOutput)
		{
			$ports->add(
				new Port(
					id: self::SOURCE_PORT_ID,
					direction: PortDirection::Output,
					position: self::PORT_POSITION,
				)
			);
		}

		return $ports;
	}

	private function makeConnections(AgentConnectionCollection $connections): ConnectionCollection
	{
		$collection = new ConnectionCollection();
		foreach ($connections as $connection)
		{
			$sourceBlockId = $this->makeActivityId($connection->sourceBlockId);
			$targetBlockId = $this->makeActivityId($connection->destinationBlockId);
			$collection->add(
				new Connection(
					id: "{$sourceBlockId}_{$targetBlockId}",
					sourceBlockId: $sourceBlockId,
					sourcePortId: self::SOURCE_PORT_ID,
					targetBlockId: $targetBlockId,
					targetPortId: self::TARGET_PORT_ID,
				)
			);
		}

		return $collection;
	}

	private function makePortsByBlock(AgentBlock $block): PortCollection
	{
		return $this->makePorts(
			hasInput: $this->makeNodeType($block->type) !== NodeType::Trigger,
			hasOutput: true,
		);
	}

	private function makeActivityProperties(AgentBlock $block): array
	{
		$map = [self::TITLE_PROPERTY_NAME => $block->title];
		foreach ($block->settings as $setting)
		{
			$map[$setting->name] = $setting->value;
		}

		return $map;
	}

	private function makeNodeType(string $agentBlockType): NodeType
	{
		if (str_ends_with(mb_strtolower($agentBlockType), 'trigger'))
		{
			return NodeType::Trigger;
		}

		return NodeType::Simple;
	}

	private function makeActivityId(string $agentBlockId): string
	{
		return is_numeric($agentBlockId) ? "MartaGeneratedId-{$this->uniqueBlockSalt}-{$agentBlockId}" : $agentBlockId;
	}

	public function convertDraftToAgentTemplate(Draft $draft): AgentTemplate
	{
		return new AgentTemplate(
			blocks: $this->convertToAgentBlocks($draft->blocks),
			connections: $this->convertToAgentConnections($draft->connections),
		);
	}

	private function convertToAgentBlocks(BlockCollection $blocks): AgentBlockCollection
	{
		$agentBlocks = new AgentBlockCollection();
		foreach ($blocks as $block)
		{
			$agentBlocks->add($this->convertToAgentBlock($block));
		}

		return $agentBlocks;
	}

	private function convertToAgentBlock(Block $block): AgentBlock
	{
		return new AgentBlock(
			type: $block->activityData->type,
			title: $block->title,
			id: $block->id,
			settings: $this->convertToAgentSettings($block),
		);
	}

	private function convertToAgentSettings(Block $block): AgentSettingCollection
	{
		$settings = new AgentSettingCollection();

		foreach ($block->activityData->properties as $name => $value)
		{
			if ($name === self::TITLE_PROPERTY_NAME)
			{
				continue;
			}

			$settings->add(new AgentSetting(
				name: $name,
				value: $value,
			));
		}

		return $settings;
	}

	private function convertToAgentConnections(ConnectionCollection $connections): AgentConnectionCollection
	{
		$agentConnections = new AgentConnectionCollection();
		foreach ($connections as $connection)
		{
			$agentConnections->add($this->convertToAgentConnection($connection));
		}

		return $agentConnections;
	}

	private function convertToAgentConnection(Connection $connection): AgentConnection
	{
		return new AgentConnection(
			destinationBlockId: $connection->targetBlockId,
			sourceBlockId: $connection->sourceBlockId,
		);
	}
}