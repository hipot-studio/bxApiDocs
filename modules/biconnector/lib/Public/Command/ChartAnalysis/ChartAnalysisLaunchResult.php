<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Command\ChartAnalysis;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Outcome of a chart analysis launch: the chat created for it, or the refusal that stopped it before anything
 * was written. The refusal is also the code of the single error of the result.
 */
final class ChartAnalysisLaunchResult extends Result
{
	public const REFUSAL_UNAVAILABLE = 'unavailable';
	public const REFUSAL_SUBSCRIPTION_REQUIRED = 'subscription_required';
	public const REFUSAL_INVALID_PAYLOAD = 'invalid_payload';
	public const REFUSAL_FOREIGN_DASHBOARD = 'foreign_dashboard';
	public const REFUSAL_TOO_LARGE = 'too_large';
	public const REFUSAL_RATE_LIMITED = 'rate_limited';
	public const REFUSAL_CHAT_FAILED = 'chat_failed';

	public const REFUSALS = [
		self::REFUSAL_UNAVAILABLE,
		self::REFUSAL_SUBSCRIPTION_REQUIRED,
		self::REFUSAL_INVALID_PAYLOAD,
		self::REFUSAL_FOREIGN_DASHBOARD,
		self::REFUSAL_TOO_LARGE,
		self::REFUSAL_RATE_LIMITED,
		self::REFUSAL_CHAT_FAILED,
	];

	private int $chatId = 0;
	private int $botId = 0;
	private string $refusal = '';
	private ?string $promoterCode = null;
	private string $vizType = '';
	private ?string $previousStatus = null;
	private bool $isContinuation = false;

	public function getChatId(): int
	{
		return $this->chatId;
	}

	public function setChatId(int $chatId): self
	{
		$this->chatId = $chatId;

		return $this;
	}

	/**
	 * @return int Id of the bot that answers in the chat; 0 until the chat is written.
	 */
	public function getBotId(): int
	{
		return $this->botId;
	}

	public function setBotId(int $botId): self
	{
		$this->botId = $botId;

		return $this;
	}

	/**
	 * @return string One of the REFUSAL_* constants; empty on success.
	 */
	public function getRefusal(): string
	{
		return $this->refusal;
	}

	public function refuse(string $refusal, string $message, ?array $customData = null): self
	{
		$this->refusal = $refusal;
		$this->addError(new Error($message, $refusal, $customData));

		return $this;
	}

	public function getPromoterCode(): ?string
	{
		return $this->promoterCode;
	}

	public function setPromoterCode(?string $promoterCode): self
	{
		$this->promoterCode = $promoterCode;

		return $this;
	}

	/**
	 * @return string The chart type as the iframe reported it; empty until the payload is read.
	 */
	public function getVizType(): string
	{
		return $this->vizType;
	}

	public function setVizType(string $vizType): self
	{
		$this->vizType = $vizType;

		return $this;
	}

	/**
	 * @return bool True when the card was written into the chat the page asked for, not into a new one.
	 */
	public function isContinuation(): bool
	{
		return $this->isContinuation;
	}

	public function setContinuation(bool $isContinuation): self
	{
		$this->isContinuation = $isContinuation;

		return $this;
	}

	public function getPreviousStatus(): ?string
	{
		return $this->previousStatus;
	}

	public function setPreviousStatus(?string $previousStatus): self
	{
		$this->previousStatus = $previousStatus;

		return $this;
	}
}
