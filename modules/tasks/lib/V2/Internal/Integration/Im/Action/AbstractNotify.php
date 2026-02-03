<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Im\Bot\Keyboard;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Time\Trait\FormatElapsedTimeTrait;
use Bitrix\Tasks\V2\Internal\Service\User\Trait\FormatUserTrait;

abstract class AbstractNotify
{
	use FormatUserTrait;
	use FormatElapsedTimeTrait;

	public function __construct(
		protected readonly ?Entity\User $triggeredBy = null,
	)
	{
	}

	public function __toString(): string
	{
		return $this->toString();
	}

	public function toString(): string
	{
		return $this->getMessage($this->getMessageCode(), $this->getMessageData()) ?? $this->getMessageCode();
	}

	public function toPluralString(int $count = 1): string
	{
		return $this->getMessagePlural($this->getMessageCode(), $count, $this->getMessageData());
	}

	public function getMessage(string $code, array $data = [])
	{
		return Loc::getMessage($code, $data);
	}

	public function getMessagePlural(string $code, int $count = 1, array $data = [])
	{
		return Loc::getMessagePlural($code, $count, $data);
	}

	/** @return Role[] */
	public function getRecipients(): array
	{
		$recipients = array_map(
			fn (Recipients $recipients): iterable => $recipients->getRecipients(),
			Recipients::getFromNotification($this),
		);

		return array_unique(array_merge(...$recipients), flags: \SORT_REGULAR);
	}

	public function getTriggeredBy(): ?Entity\User
	{
		return $this->triggeredBy;
	}

	abstract public function getMessageCode(): string;

	public function getKeyboard(): ?Keyboard
	{
		return null;
	}

	public function getAttach(): ?\CIMMessageParamAttach
	{
		return null;
	}

	public function getMessageData(): array
	{
		return [];
	}

	public function getMessageParams(): array
	{
		return [];
	}

	public function getDisableNotify(): bool
	{
		return false;
	}

	public function shouldDisableGenerateUrlPreview(): bool
	{
		return true;
	}

	public function shouldDisableAddRecent(): bool
	{
		return false;
	}

	protected function stripBbCodeUrl(string $text): string
	{
		return preg_replace('#\[URL=[^]]*]([^\[]*)\[/URL]#i', '$1', $text);
	}
}
