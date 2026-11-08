<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\TemplateNoteService;

/**
 * The outcome of notifying the users mentioned in a saved message. It is the same three values for
 * every operation that writes a message, and it never decides the operation itself: the message is
 * already saved, the editor only tells the author who was left out and why.
 */
trait MentionNotificationTrait
{
	/**
	 * @return int[] ids of the users the notification has reached
	 */
	public function getNotified(): array
	{
		return $this->data['notified'] ?? [];
	}

	/**
	 * @param int[] $userIds
	 */
	public function setNotified(array $userIds): static
	{
		$this->data['notified'] = $userIds;

		return $this;
	}

	/**
	 * @return int[] ids of the mentioned users who may not see the template and were not notified
	 */
	public function getUnavailableMentions(): array
	{
		return $this->data['unavailableMentions'] ?? [];
	}

	/**
	 * @param int[] $userIds
	 */
	public function setUnavailableMentions(array $userIds): static
	{
		$this->data['unavailableMentions'] = $userIds;

		return $this;
	}

	public function isNotifyFailed(): bool
	{
		return $this->data['notifyFailed'] ?? false;
	}

	public function setNotifyFailed(bool $notifyFailed): static
	{
		$this->data['notifyFailed'] = $notifyFailed;

		return $this;
	}
}
