<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Link;

use Bitrix\Socialnetwork\Collab\Integration\Extranet\Extranet;
use Bitrix\Socialnetwork\Collab\User\User;
use Bitrix\Socialnetwork\Helper\Analytics\Analytics;
use Bitrix\Socialnetwork\Helper\InstanceTrait;

final class LinkManager
{
	use InstanceTrait;

	private LinkType $type;
	private LinkParts $linkParts;
	private User $user;

	public function get(LinkType $type, LinkParts $linkParts): string
	{
		$this->type = $type;
		$this->linkParts = $linkParts;
		$this->user = new User($this->linkParts->userId);

		$parts = $this->getParts();

		$baseLink = match ($this->type)
		{
			LinkType::Tasks, LinkType::Calendar, LinkType::Disk, => implode('/', $parts) . '/',
		};

		$link = $this->linkParts->entityId === null ? $baseLink : $this->getEntityLink($baseLink);

		return $link . $this->getAnalyticsQuery();
	}

	/**
	 * Marks the files section link so its landing page can send open_section. Tasks and Calendar carry their own
	 * analytics and must stay untouched.
	 */
	private function getAnalyticsQuery(): string
	{
		if ($this->type !== LinkType::Disk)
		{
			return '';
		}

		return '?c_element=' . Analytics::ELEMENT_FILES_BUTTON;
	}

	private function getEntityLink(string $baseLink): string
	{
		$parts = $this->getEntityParts();

		return match ($this->type)
		{
			LinkType::Tasks => $baseLink . implode('/', $parts) . '/',
			default => $baseLink,
		};
	}

	private function getParts(): array
	{
		$sitePart = $this->getSitePart();
		if (empty($sitePart))
		{
			return [$this->getCollabPart(), $this->getEntityPart()];
		}

		return [$this->getSitePart(), $this->getCollabPart(), $this->getEntityPart()];
	}

	private function getEntityParts(): array
	{
		if (empty($this->linkParts->view))
		{
			return [$this->linkParts->entityId];
		}

		$entityType = $this->getEntityType();
		if (empty($entityType))
		{
			return [$this->linkParts->view, $this->linkParts->entityId];
		}

		return [$entityType, $this->linkParts->view, $this->linkParts->entityId];
	}

	private function getSitePart(): string
	{
		if ($this->user->isExtranet())
		{
			return Extranet::getSiteName();
		}

		return '';
	}

	private function getCollabPart(): string
	{
		return 'workgroups/group/' . $this->linkParts->collabId;
	}

	private function getEntityPart(): string
	{
		return match ($this->type)
		{
			LinkType::Tasks => 'tasks',
			LinkType::Calendar => 'calendar',
			LinkType::Disk => 'disk/path',
		};
	}

	private function getEntityType(): string
	{
		return match ($this->type)
		{
			LinkType::Tasks => 'task',
			default => '',
		};
	}
}
