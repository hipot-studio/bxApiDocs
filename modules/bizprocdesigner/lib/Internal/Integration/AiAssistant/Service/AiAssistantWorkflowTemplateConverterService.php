<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentTemplate;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class AiAssistantWorkflowTemplateConverterService
{
	private ?AgentBlockCollection $agentBlocks = null;
	private ?AgentConnectionCollection $agentConnections = null;

	public function getAgentTemplate(): ?AgentTemplate
	{
		if ($this->agentBlocks && $this->agentConnections)
		{
			return new AgentTemplate(
				blocks: $this->agentBlocks,
				connections: $this->agentConnections,
			);
		}

		return null;
	}

	public function convertFromTemplateArrayToAgentTemplate(array $template): Result
	{
		$this->agentBlocks = null;
		$this->agentConnections = null;

		if (!Loader::includeModule('bizproc'))
		{
			return (new Result())->addError(new Error('Module "bizproc" is not installed.'));
		}

		$result = $this->convertTemplateArrayToAgentBlocks($template);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result = $this->convertTemplateArrayToAgentConnections($template);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return new Result();
	}

	private function convertTemplateArrayToAgentBlocks(array $template): Result
	{
		$rootElement = $template[0] ?? [];
		$rootElementType = $rootElement[NodesToTemplate::ELEMENT_TYPE] ?? null;
		if ($rootElementType !== NodesToTemplate::ROOT_NODE_TYPE)
		{
			return (new Result())
				->addError(new Error('Invalid template format: root element must be ' . NodesToTemplate::ROOT_NODE_TYPE))
			;
		}

		$rootElementChildren = $rootElement[NodesToTemplate::ELEMENT_CHILDREN] ?? [];
		if (!is_array($rootElementChildren)) {

			return (new Result())
				->addError(new Error('Invalid template format: root element children must be an array'))
			;
		}

		$agentBlocks = new AgentBlockCollection();
		foreach ($rootElementChildren as $child)
		{
			$type = $child[NodesToTemplate::ELEMENT_TYPE] ?? null;
			$id = $child[NodesToTemplate::ELEMENT_NAME] ?? null;
			$properties = $child[NodesToTemplate::ELEMENT_PROPERTIES] ?? [];
			$result = $this->validateBlock($type, $id, $properties);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$agentBlocks->add(
				new AgentBlock(
					type: $type,
					title: (string)($properties[NodesToTemplate::PROPERTY_TITLE] ?? ''),
					id: $id,
					settings: $this->convertPropertiesToAgentSettings($properties),
				)
			);
		}

		$this->agentBlocks = $agentBlocks;

		return new Result();
	}

	private function convertTemplateArrayToAgentConnections(array $template): Result
	{
		$rootElement = $template[0] ?? [];
		$links = $rootElement[NodesToTemplate::ELEMENT_PROPERTIES][NodesToTemplate::PROPERTY_LINKS] ?? [];
		if (!is_array($links))
		{
			return (new Result())
				->addError(new Error('Invalid template format: root element links must be an array'))
			;
		}

		$agentConnections = new AgentConnectionCollection();
		foreach ($links as $linkKey => $link)
		{
			$sourceBlockId = $this->getLinkSourceBlockId($link);
			$destinationBlockId = $this->getLinkDestinationBlockId($link);
			if (!$this->isLinkValid($sourceBlockId, $destinationBlockId))
			{
				return (new Result())->addError(new Error("Invalid link $linkKey format"));
			}

			$agentConnections->add(
				new AgentConnection(
					destinationBlockId: $destinationBlockId,
					sourceBlockId: $sourceBlockId,
				)
			);
		}

		$this->agentConnections = $agentConnections;

		return new Result();
	}

	private function convertPropertiesToAgentSettings(array $properties): AgentSettingCollection
	{
		$settings = new AgentSettingCollection();

		foreach ($properties as $name => $value)
		{
			if ($name === NodesToTemplate::PROPERTY_TITLE)
			{
				continue;
			}

			$settings->add(new AgentSetting(
				name: (string)$name,
				value: is_array($value) ? $value : (string)$value,
			));
		}

		return $settings;
	}

	private function getLinkSourceBlockId(mixed $linkElement): ?string
	{
		return $this->getLinkBlockId($linkElement[0] ?? null);
	}

	private function getLinkDestinationBlockId(mixed $linkElement): ?string
	{
		return $this->getLinkBlockId($linkElement[1] ?? null);
	}

	private function getLinkBlockId(mixed $linkWithPort): ?string
	{
		if (!is_string($linkWithPort))
		{
			return null;
		}

		$linkIdentifierParts = explode(NodesToTemplate::LINK_DELIMITER, $linkWithPort);

		return $linkIdentifierParts[0] ?? null;
	}

	private function isLinkValid(?string $sourceBlockId, ?string $destinationBlockId): bool
	{
		return $this->isBlockIdentifierValid($sourceBlockId)
			&& $this->isBlockIdentifierValid($destinationBlockId)
		;
	}

	private function isBlockIdentifierValid(?string $blockIdentifier): bool
	{
		return is_string($blockIdentifier) && $blockIdentifier !== '';
	}

	private function validateBlock(mixed $type, mixed $id, mixed $properties): Result
	{
		$result = new Result();

		if (!is_string($id) || $id === '')
		{
			$result->addError(new Error('Block Name is not specified'));
		}

		if (!is_string($type) || $type === '')
		{
			$result->addError(new Error('Block Type is not specified'));
		}

		if (!is_array($properties))
		{
			$result->addError(new Error('Block Properties are not array'));
		}

		return $result;
	}
}