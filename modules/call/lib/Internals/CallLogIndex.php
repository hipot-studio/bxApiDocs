<?php

namespace Bitrix\Call\Internals;

class CallLogIndex
{
	private const CHARS_TO_REPLACE = ['(', ')', '[', ']', '{', '}', '<', '>', '-', '#', '"', '\''];

	private int $callLogId;
	private array $phoneNumbers = [];
	private array $userNames = [];
	private string $title = '';

	public static function create(): CallLogIndex
	{
		return new static();
	}

	private function __construct()
	{
	}

	public function getCallLogId(): int
	{
		return $this->callLogId;
	}

	public function setCallLogId(int $callLogId): CallLogIndex
	{
		$this->callLogId = $callLogId;
		return $this;
	}

	public function getPhoneNumbers(): array
	{
		return $this->phoneNumbers;
	}

	public function setPhoneNumbers(array $phoneNumbers): CallLogIndex
	{
		$this->phoneNumbers = $phoneNumbers;
		return $this;
	}

	public function getUserNames(): array
	{
		return $this->userNames;
	}

	public function setUserNames(array $userNames): CallLogIndex
	{
		$this->userNames = $userNames;
		return $this;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getClearedTitle(): string
	{
		return self::clearText($this->title);
	}

	public function setTitle(string $title): CallLogIndex
	{
		$this->title = $title;
		return $this;
	}

	public function getClearedUserNames(): array
	{
		$cleared = [];
		foreach ($this->userNames as $name)
		{
			$cleared[] = self::clearText($name);
		}
		return $cleared;
	}

	public function getClearedPhoneNumbers(): array
	{
		$cleared = [];
		foreach ($this->phoneNumbers as $phone)
		{
			$cleared[] = self::clearText($phone);
		}
		return $cleared;
	}

	public static function clearText(string $text): string
	{
		$clearedText = str_replace(static::CHARS_TO_REPLACE, ' ', $text);
		$clearedText = preg_replace('/\s+/', ' ', $clearedText);
		return trim($clearedText);
	}
}
