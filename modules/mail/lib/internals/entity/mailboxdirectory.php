<?php

namespace Bitrix\Mail\Internals\Entity;

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\MailboxDirectory as MailboxDirectoryManager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Text\Emoji;
use JsonSerializable;

class MailboxDirectory extends \Bitrix\Mail\Internals\EO_MailboxDirectory implements JsonSerializable
{
	private $children = [];

	private ?bool $nestedInVirtualFolder = null;

	public function isSync()
	{
		if ((int)$this->getIsSync() === (int)MailboxDirectoryTable::ACTIVE)
		{
			return true;
		}

		return false;
	}

	public function isDisabled()
	{
		if ((int)$this->getIsDisabled() === (int)MailboxDirectoryTable::ACTIVE)
		{
			return true;
		}

		return false;
	}

	/**
	 * @deprecated Use \Bitrix\Mail\Internals\Entity\MailboxDirectory::isVirtualFolder
	 * @return bool
	 */
	public function isHiddenSystemFolder(): bool
	{
		return $this->isVirtualFolder();
	}

	public function isVirtualFolder(): bool
	{
		if (!$this->isDisabled())
		{
			return false;
		}

		$containerFolders = [
			'[Gmail]',
		];

		return in_array($this->getPath(), $containerFolders, true);
	}

	/**
	 * @return string[]
	 */
	protected function getAliasExceptionPaths(): array
	{
		$delimiter = $this->getDelimiter();

		$exceptionPaths = [
			'Drafts%stemplate',
			'INBOX%sSocial',
			'INBOX%sNewsletters',
			'INBOX%sToMyself',
			'INBOX%sNews',
			'INBOX%sReceipts',
			'INBOX%sPublic services',
			'INBOX%sSchool',
			'INBOX%sGames',
		];

		return array_map((static fn ($item) => sprintf($item, $delimiter)), $exceptionPaths);
	}

	public function isAliasException(): bool
	{
		return in_array($this->getPath(), $this->getAliasExceptionPaths(), true);
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function isNestedInVirtualFolder(): bool
	{
		if ($this->nestedInVirtualFolder !== null)
		{
			return $this->nestedInVirtualFolder;
		}

		$rootId = $this->getRootId();
		if ($rootId <= 0)
		{
			$this->nestedInVirtualFolder = false;

			return false;
		}

		$root = MailboxDirectoryTable::getById($rootId)->fetchObject();
		$this->nestedInVirtualFolder = $root && $root->isVirtualFolder();

		return $this->nestedInVirtualFolder;
	}

	public function isSpam()
	{
		if ((int)$this->getIsSpam() === (int)MailboxDirectoryTable::ACTIVE)
		{
			return true;
		}

		return false;
	}

	public function isTrash()
	{
		if ((int)$this->getIsTrash() === (int)MailboxDirectoryTable::ACTIVE)
		{
			return true;
		}

		return false;
	}

	public function isDraft()
	{
		if ((int)$this->getIsDraft() === (int)MailboxDirectoryTable::ACTIVE)
		{
			return true;
		}

		return false;
	}

	public function isOutcome()
	{
		if ((int)$this->getIsOutcome() === (int)MailboxDirectoryTable::ACTIVE)
		{
			return true;
		}

		return false;
	}

	public function isInvisibleToCounters(): bool
	{
		if($this->isTrash() || $this->isSpam() || $this->isDraft() || $this->isOutcome())
		{
			return true;
		}

		return false;
	}

	public function isIncome()
	{
		if ((int)$this->getIsIncome() === (int)MailboxDirectoryTable::ACTIVE)
		{
			return true;
		}

		return false;
	}

	public function hasChildren()
	{
		return !empty($this->children);
	}

	public function getChildren()
	{
		return $this->children;
	}

	public function addChild($dir)
	{
		$this->children[] = $dir;

		return $this;
	}

	public function getCountChildren()
	{
		$count = 0;

		foreach ($this->children as $child)
		{
			$count++;

			if ($child->hasChildren())
			{
				$count += $child->getCountChildren();
			}
		}

		return $count;
	}

	public function getCountSyncChildren()
	{
		$count = 0;

		foreach ($this->children as $child)
		{
			if ($child->isSync())
			{
				$count++;
			}

			$count += $child->getCountSyncChildren();
		}

		return $count;
	}

	/**
	 * @return string
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getFormattedName()
	{
		if ($this->getLevel() === 1)
		{
			return $this->getName();
		}

		$path = explode($this->getDelimiter(), $this->getPath());
		if ($this->isAliasException() || $this->isNestedInVirtualFolder())
		{
			$path[count($path) - 1] = $this->getName();
		}

		return join(' / ', $path);
	}

	public function getPath($emojiEncode = false)
	{
		if(!$emojiEncode)
		{
			return parent::getPath();
		}
		return Emoji::encode(parent::getPath());
	}

	/**
	 * @return string
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getName(): string
	{
		$name = $this->sysGetValue('NAME');

		if ($this->isAliasException())
		{
			return $this->getAliasNameByType() ?? $this->getAliasNameByName($name) ?? $name;
		}

		if ($this->getLevel() !== 1 && !$this->isNestedInVirtualFolder())
		{
			return $name;
		}

		return $this->getAliasNameByType() ?? $this->getAliasNameByName($name) ?? $name;
	}

	public function getAliasNameByType(): ?string
	{
		return match (true) {
			$this->isIncome() => Loc::getMessage('MAIL_CLIENT_INBOX_ALIAS'),
			$this->isOutcome() => Loc::getMessage('MAIL_CLIENT_OUTCOME_ALIAS'),
			$this->isDraft() => Loc::getMessage('MAIL_CLIENT_DRAFT_ALIAS'),
			$this->isTrash() => Loc::getMessage('MAIL_CLIENT_TRASH_ALIAS'),
			$this->isSpam() => Loc::getMessage('MAIL_CLIENT_SPAM_ALIAS'),
			default => null,
		};
	}

	public function getAliasNameByName(string $name): ?string
	{
		$normalizedName = mb_strtolower($name);
		return match ($normalizedName) {
			'inbox' => Loc::getMessage('MAIL_CLIENT_INBOX_ALIAS'),
			'outbox' => Loc::getMessage('MAIL_CLIENT_OUTBOX_ALIAS'),
			'outcome' => Loc::getMessage('MAIL_CLIENT_OUTCOME_ALIAS'),
			'draft' => Loc::getMessage('MAIL_CLIENT_DRAFT_ALIAS'),
			'trash' => Loc::getMessage('MAIL_CLIENT_TRASH_ALIAS'),
			'spam' => Loc::getMessage('MAIL_CLIENT_SPAM_ALIAS'),
			'template' => Loc::getMessage('MAIL_CLIENT_TEMPLATE_ALIAS'),
			'social' => Loc::getMessage('MAIL_CLIENT_SOCIAL_ALIAS'),
			'newsletters' => Loc::getMessage('MAIL_CLIENT_NEWSLETTERS_ALIAS'),
			'tomyself' => Loc::getMessage('MAIL_CLIENT_TOMYSELF_ALIAS'),
			'news' => Loc::getMessage('MAIL_CLIENT_NEWS_ALIAS'),
			'receipts' => Loc::getMessage('MAIL_CLIENT_RECEIPTS_ALIAS'),
			'public services' => Loc::getMessage('MAIL_CLIENT_PUBLIC_SERVICES_ALIAS'),
			'games' => Loc::getMessage('MAIL_CLIENT_SCHOOL_ALIAS'),
			'school' => Loc::getMessage('MAIL_CLIENT_GAMES_ALIAS'),
			'archive' => Loc::getMessage('MAIL_CLIENT_ARCHIVE_ALIAS'),
			'notes' => Loc::getMessage('MAIL_CLIENT_NOTES_ALIAS'),
			default => null,
		};
	}

	public function isSyncLock()
	{
		if ($this->getSyncLock() > time() - Mailbox::getTimeout())
		{
			return true;
		}

		return false;
	}

	public function startSyncLock()
	{
		$this->setSyncLock(time());

		if (MailboxDirectoryManager::setSyncLock($this->getId(), $this->getSyncLock()) > 0)
		{
			return true;
		}

		return false;
	}

	public function stopSyncLock()
	{
		$this->unsetSyncLock();
		$this->setSyncLock(null);

		$this->save();
	}

	/**
	 * @inheritDoc
	 */
	public function jsonSerialize()
	{
		return [
			'ID'             => $this->getId(),
			'MAILBOX_ID'     => $this->getMailboxId(),
			'NAME'           => $this->getName(),
			'FORMATTED_NAME' => $this->getFormattedName(),
			'PATH'           => $this->getPath(),
			'FLAGS'          => $this->getFlags(),
			'DELIMITER'      => $this->getDelimiter(),
			'DIR_MD5'        => $this->getDirMd5(),
			'LEVEL'          => $this->getLevel(),
			'IS_DISABLED'    => $this->isDisabled(),
			'IS_CONTAINER'   => $this->isVirtualFolder(),
			'CHILDREN'       => Json::decode(Json::encode($this->getChildren()))
		];
	}
}
