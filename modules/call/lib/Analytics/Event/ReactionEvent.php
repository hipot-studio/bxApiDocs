<?php

namespace Bitrix\Call\Analytics\Event;

use Bitrix\Call\Call;

/**
 * @internal
 */
class ReactionEvent extends Event
{
	// Where the reaction was picked from. The client names it, so the set is closed here.
	public const SOURCE_QUICK_SET = 'quick_set';
	public const SOURCE_EXTENDED_LIST = 'extended_list';

	private string $reactionId;
	private string $source;

	public function __construct(string $eventName, Call $call, string $reactionId, string $source = '')
	{
		// Set before the parent constructor: it runs setDefaultParams(), which needs them.
		$this->reactionId = $reactionId;
		$this->source = $source;

		parent::__construct($eventName, $call);
	}

	public static function isKnownSource(string $source): bool
	{
		return in_array($source, [self::SOURCE_QUICK_SET, self::SOURCE_EXTENDED_LIST], true);
	}

	protected function setDefaultParams(): self
	{
		$this
			->setCallType()
			->setReactionSource()
			->setReactionP1()
			->setCallP5()
		;

		return $this;
	}

	/**
	 * Unlike CallEvent, conferences are matched before group calls: ConferenceCall extends
	 * BitrixCall, so the wider arm would swallow them.
	 */
	protected function setCallType(): self
	{
		$this->type = match(true)
		{
			$this->call instanceof \Bitrix\Call\Call\ConferenceCall => 'videoconf',
			$this->call instanceof \Bitrix\Call\Call\PlainCall => 'private',
			$this->call instanceof \Bitrix\Call\Call\BitrixCall => 'group',
			default => 'unknown',
		};

		return $this;
	}

	/**
	 * An unknown source leaves the field unset: the event is still worth sending without it.
	 */
	protected function setReactionSource(): self
	{
		if (self::isKnownSource($this->source))
		{
			$this->element = $this->source;
		}

		return $this;
	}

	protected function setReactionP1(): self
	{
		$this->p1 = 'reactionType_' . $this->reactionId;

		return $this;
	}

	protected function setCallP5(): self
	{
		if ($this->call->getUuid() !== null)
		{
			$this->p5 = 'callId_' . $this->call->getUuid();
		}

		return $this;
	}

	protected function getCategory(string $eventName): string
	{
		return 'call';
	}
}
