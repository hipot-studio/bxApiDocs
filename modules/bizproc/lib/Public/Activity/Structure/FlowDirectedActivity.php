<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Structure;

use Bitrix\Bizproc\Internal\Entity\Port\PortType;
use CBPActivity;
use CBPActivityExecutionResult;
use CBPActivityExecutionStatus;
use CBPArgumentNullException;

/**
 * @property-read string $Title
 * @property-read array $Links
 */
abstract class FlowDirectedActivity extends \CBPCompositeActivity implements \IBPActivityEventListener
{
	protected const PARAM_LINKS = 'Links';
	public const LINK_DELIMITER = ':';
	public const LINK_SOURCE = 0;
	public const LINK_TARGET = 1;

	protected array $activityQueue = [];
	protected array $pendingQueue = [];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::PARAM_LINKS => [],
		];
	}

	public function execute(): int
	{
		$startActivityNames = $this->getStartActivityNames();
		if ($startActivityNames && $this->executeByNames($this, $startActivityNames))
		{
			return CBPActivityExecutionStatus::Executing;
		}

		return CBPActivityExecutionStatus::Closed;
	}

	/**
	 * @return list<string>
	 */
	abstract protected function getStartActivityNames(): array;

	/**
	 * @param CBPActivity $sender
	 * @param list<string> $names
	 * @return bool
	 * @throws CBPArgumentNullException
	 */
	protected function executeByNames(CBPActivity $sender, array $names): bool
	{
		foreach ($names as $activityName)
		{
			$inputPort = 0;
			$originalActivityName = $activityName;
			if (str_contains($activityName, $this->getInputPortPrefix()))
			{
				[$activityName, $inputPort] = explode($this->getInputPortPrefix(), $activityName);
				$inputPort = (int)$inputPort;
			}

			$activity = $this->workflow->getActivityByName($activityName);
			if (!$activity)
			{
				continue;
			}

			if (CBPActivityExecutionStatus::isInProgress($activity->executionStatus))
			{
				$this->subscribeActivity($activity);
				$this->pendingQueue[$activity->getName()][] = [$sender, [$originalActivityName]];

				continue;
			}

			$this->executeActivity($sender, $activity, $inputPort);
		}

		return !empty($this->activityQueue);
	}

	protected function executeActivity(CBPActivity $sender, CBPActivity $activity, int $inputPort): void
	{
		$this->subscribeActivity($activity);
		$activity->reInitialize();
		$payload = $this->workflow->executeActivity($activity);

		$payload
			->setParentName($sender->getName())
			->setParentPort($sender->getOutputPortId())
			->setInputPort($inputPort)
		;
	}

	private function subscribeActivity(CBPActivity $activity): void
	{
		if (!isset($this->activityQueue[$activity->getName()]))
		{
			$activity->addStatusChangeHandler(static::ClosedEvent, $this);
			$this->activityQueue[$activity->getName()] = true;
		}
	}

	public function onEvent(CBPActivity $sender, $arEventParameters = []): void
	{
		$sender->removeStatusChangeHandler(static::ClosedEvent, $this);
		unset($this->activityQueue[$sender->getName()]);

		if ($sender->executionResult === CBPActivityExecutionResult::Succeeded)
		{
			$next = $this->getOutputNames($sender->getName(), [$sender->getOutputPortId()]);
			if ($next)
			{
				$this->executeByNames($sender, $next);
			}
			else
			{
				$this->onDeadEndReached($sender);
			}
		}

		$this->executePendingQueue($sender->getName());

		if (empty($this->activityQueue))
		{
			$this->close();
		}
	}

	private function executePendingQueue($name)
	{
		$queue = $this->pendingQueue[$name] ?? [];
		unset($this->pendingQueue[$name]);

		foreach ($queue as [$sender, $names])
		{
			$this->executeByNames($sender, $names);
		}
	}

	abstract protected function onDeadEndReached(CBPActivity $lastActivity): void;

	protected function close(): void
	{
		$this->workflow->closeActivity($this);
	}

	/**
	 * @param string $name
	 * @param array $outputIds
	 * @return list<string> Returns array of strings activity name with port like ['A1111_2222_3333_4444:i0']
	 */
	public function getOutputNames(string $name, array $outputIds = [0]): array
	{
		$links = $this->getRawProperty(self::PARAM_LINKS);

		$found = [];
		foreach ($outputIds as $outputId)
		{
			$haystack = [static::createOutputName($name, $outputId) => true];
			if ($outputId === 0)
			{
				$haystack[$name] = true;
			}

			$found[] = array_filter(
				$links,
				fn($link) =>
					isset($haystack[$link[self::LINK_SOURCE]])
					&& str_contains($link[self::LINK_TARGET], $this->getInputPortPrefix())
			);
		}

		return array_values(array_column(array_merge(...$found), self::LINK_TARGET));
	}

	protected static function createOutputName(string $name, int $outputId): string
	{
		return self::composeLink($name, PortType::Output, $outputId);
	}

	/**
	 * @param string $name
	 * @param array $inputIds
	 *
	 * @return list<string> Returns array of strings activity name with port like ['A1111_2222_3333_4444:i0']
 */
	public function getInputNames(string $name, array $inputIds = [0]): array
	{
		$links = $this->getRawProperty(self::PARAM_LINKS);

		$found = [];
		foreach ($inputIds as $inputId)
		{
			$haystack = [static::createInputName($name, $inputId) => true];
			if ($inputId === 0)
			{
				$haystack[$name] = true;
			}

			$found[] = array_filter(
				$links,
				fn($link) =>
					isset($haystack[$link[self::LINK_TARGET]])
					&& str_contains($link[self::LINK_SOURCE], $this->getOutputPrefix())
			);
		}

		return array_values(array_column(array_merge(...$found), self::LINK_SOURCE));
	}

	protected static function createInputName(string $name, int $inputId): string
	{
		return self::composeLink($name, PortType::Input, $inputId);
	}

	private function getInputPortPrefix(): string
	{
		return self::LINK_DELIMITER . PortType::Input->value;
	}

	private function getOutputPrefix(): string
	{
		return self::LINK_DELIMITER . PortType::Output->value;
	}

	private static function composeLink(string $name, PortType $portType, int $portId = 0): string
	{
		return $name . self::LINK_DELIMITER . $portType->value . $portId;
	}

	/**
	 * @param string $sourceActivityName
	 *
	 * @return list<string> Returns array of strings activity name with port like ['A1111_2222_3333_4444:i0']
	 */
	public function getAuxNames(string $sourceActivityName): array
	{
		$names = [];
		$sourceLink = static::composeLink($sourceActivityName, PortType::Aux);
		$links = $this->getRawProperty(self::PARAM_LINKS);
		foreach ($links as $link)
		{
			if (!isset($link[self::LINK_SOURCE], $link[self::LINK_TARGET]))
			{
				continue;
			}

			$anotherActivityPort = null;
			[$sourceNodeLink, $targetNodeLink] = $link;
			if ($targetNodeLink === $sourceLink)
			{
				$anotherActivityPort = $sourceNodeLink;
			}

			if ($sourceNodeLink === $sourceLink)
			{
				$anotherActivityPort = $targetNodeLink;
			}

			if (!empty($anotherActivityPort))
			{
				$names[] = $anotherActivityPort;
			}
		}

		return $names;
	}

	/**
	 * @param string $activityNameWithPort like 'A1111_2222_3333_4444:i0'
	 *
	 * @return string|null 'A1111_2222_3333_4444'
	 */
	public function extractActivityNameFromLink(string $activityNameWithPort): ?string
	{
		$parts = explode(self::LINK_DELIMITER, $activityNameWithPort);

		return array_shift($parts);
	}

	/**
	 * @param list<string> $activityNamesWithPorts like ['A1111_2222_3333_4444:i0']
	 *
	 * @return list<string> like ['A1111_2222_3333_4444']
	 */
	public function extractActivityNamesFromLinks(array $activityNamesWithPorts): array
	{
		$names = [];
		foreach ($activityNamesWithPorts as $nameWithPort)
		{
			if (is_string($nameWithPort))
			{
				$name = $this->extractActivityNameFromLink($nameWithPort);
				if (!empty($name))
				{
					$names[] = $name;
				}
			}
		}

		return $names;
	}
}
