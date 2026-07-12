<?php

namespace Bitrix\Im\V2\Chat\EntityLink;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\EntityLink;
use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;
use Bitrix\Main\Loader;

class SonetType extends EntityLink
{
	protected function getUrl(): string
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return '';
		}

		$url = \COption::GetOptionString('socialnetwork', 'workgroups_page', '/workgroups/', SITE_ID);
		$url .= "group/{$this->entityId}/";
		if ($this->needsScrumUrlMarker())
		{
			$url .= '?scrum=y';
		}

		return $url;
	}

	/**
	 * With new projects enabled, project chats are migrated to Collabs, so any non-Collab
	 * chat reaching this entity-link type is a scrum. Without new projects, project and
	 * scrum chats share the same URL, so no marker is needed.
	 */
	protected function needsScrumUrlMarker(): bool
	{
		if (!Collab::isNewProjectsAvailable())
		{
			return false;
		}

		return !(Chat::getInstance($this->chatId) instanceof Collab);
	}
}
