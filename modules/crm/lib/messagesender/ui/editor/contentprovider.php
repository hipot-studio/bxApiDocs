<?php

namespace Bitrix\Crm\MessageSender\UI\Editor;

abstract class ContentProvider implements \JsonSerializable
{
	public function __construct(
		private readonly Context $context,
	)
	{
	}

	/**
	 * Is shown at in the editor at all. Return false to hide provider from UI.
	 */
	public function isShown(): bool
	{
		return true;
	}

	/**
	 * Returns true if provider is not available on the current tariff.
	 */
	public function isLocked(): bool
	{
		return false;
	}

	/**
	 * Is fully enabled and ready to be used by current user with current permissions.
	 */
	public function isEnabled(): bool
	{
		return true;
	}

	/**
	 * Returns a unique key for the action that is used in Editor options.
	 */
	abstract public function getKey(): string;

	final protected function getContext(): Context
	{
		return $this->context;
	}

	public function jsonSerialize(): array
	{
		return [
			'isShown' => $this->isShown(),
			'isLocked' => $this->isLocked(),
			'isEnabled' => $this->isEnabled(),
		];
	}
}
