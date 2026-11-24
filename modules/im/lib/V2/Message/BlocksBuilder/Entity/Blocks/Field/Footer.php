<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Design;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Type;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\ChipDesign;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\ChipSize;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\Kind;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List\IconType;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class Footer extends AbstractField
{
	public const MAX_ROWS = 2;
	public const MAX_ROW_ELEMENTS = 3;
	public const MAX_TITLE_LENGTH = 40;
	public const MAX_FOR_USERS = 100;

	/**
	 * Every rule below may only be loosened. The same validation runs when a stored message is read
	 * back, and a rejection leaves the message with no blocks at all: a narrowed rule breaks messages
	 * sent before it. Adding a block type or raising a limit is always safe.
	 */
	private const ALLOWED_BLOCK_TYPES = [
		BlockType::Text,
		BlockType::Title,
		BlockType::OrderedList,
		BlockType::UnorderedList,
		BlockType::Map,
		BlockType::Table,
		BlockType::Gallery,
		BlockType::AiAssistantSearch,
	];

	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if ($field === null)
		{
			return $result->setResult([]);
		}

		if (!is_array($field))
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_FIELD));
		}

		$rowsResult = $this->dropEmptyRows($field);
		if (!$rowsResult->isSuccess())
		{
			return $rowsResult;
		}

		$rows = $rowsResult->getResult();
		if (empty($rows))
		{
			return $result->setResult([]);
		}

		if (!in_array($blockType, self::ALLOWED_BLOCK_TYPES, true))
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_NOT_ALLOWED_FOR_BLOCK));
		}

		if (count($rows) > self::MAX_ROWS)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_ROWS_EXCEEDED));
		}

		$footer = [];
		foreach ($rows as $row)
		{
			$rowResult = $this->validateRow($row, $blockType);
			if (!$rowResult->isSuccess())
			{
				return $rowResult;
			}

			$footer[] = $rowResult->getResult();
		}

		return $result->setResult($footer);
	}

	/**
	 * A row is a layout, not content: an empty one is dropped instead of being rejected. An empty
	 * footer survives the same way and means the block has none - that is how an update takes the
	 * footer off a block.
	 */
	private function dropEmptyRows(array $field): Result
	{
		$result = new Result();

		$rows = [];
		foreach ($field as $row)
		{
			if (!is_array($row))
			{
				return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_FIELD));
			}

			if (!empty($row))
			{
				$rows[] = $row;
			}
		}

		return $result->setResult($rows);
	}

	private function validateRow(array $row, ?BlockType $blockType): Result
	{
		$result = new Result();

		if (count($row) > self::MAX_ROW_ELEMENTS)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_ROW_ELEMENTS_EXCEEDED));
		}

		$elements = [];
		foreach ($row as $element)
		{
			if (!is_array($element))
			{
				return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_FIELD));
			}

			$elementResult = $this->validateElement($element, $blockType);
			if (!$elementResult->isSuccess())
			{
				return $elementResult;
			}

			$elements[] = $elementResult->getResult();
		}

		return $result->setResult($elements);
	}

	private function validateElement(array $element, ?BlockType $blockType): Result
	{
		$result = new Result();

		$type = Type::tryFrom((string)($element['type'] ?? ''));
		if ($type === null || $type === Type::RequestButton)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_WRONG_ELEMENT_TYPE));
		}

		$titleResult = $this->validateTitle($element);
		if (!$titleResult->isSuccess())
		{
			return $titleResult;
		}

		// The kind is refused rather than corrected: a silent fallback would hide a sender building
		// elements of a kind the contract does not know.
		$kind = Kind::tryFrom((string)($element['kind'] ?? Kind::Button->value));
		if ($kind === null)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_WRONG_ELEMENT_KIND));
		}

		$designResult = $this->validateDesign($element, $kind);
		if (!$designResult->isSuccess())
		{
			return $designResult;
		}

		$validated = [
			'id' => (string)($element['id'] ?? ''),
			'kind' => $kind->value,
			'type' => $type->value,
			'title' => $titleResult->getResult(),
			'design' => $designResult->getResult(),
		];

		if (array_key_exists('forUsers', $element))
		{
			$forUsersResult = $this->validateForUsers($element['forUsers']);
			if (!$forUsersResult->isSuccess())
			{
				return $forUsersResult;
			}

			$validated['forUsers'] = $forUsersResult->getResult();
		}

		$appearanceResult = $this->validateAppearance($element, $kind);
		if (!$appearanceResult->isSuccess())
		{
			return $appearanceResult;
		}

		$validated += $appearanceResult->getResult();

		if ($type === Type::LinkButton)
		{
			$url = $element['url'] ?? null;
			if (!is_string($url) || $url === '' || !$this->isAllowedUrl($url))
			{
				return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_URL));
			}

			$validated['url'] = $url;

			return $result->setResult($validated);
		}

		$actionId = $element['actionId'] ?? null;
		if (!is_string($actionId) || $actionId === '')
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_EMPTY_ACTION_ID));
		}

		$validated['actionId'] = $actionId;
		$validated['actionParams'] = is_array($element['actionParams'] ?? null) ? $element['actionParams'] : null;

		return $result->setResult($validated);
	}

	/**
	 * Neither field is corrected on bad input the way `design` is: they decide whether a person
	 * recognizes the button at all, and a silent fallback would hide the sender's mistake. A button
	 * with the title hidden and no icon is turned down for the same reason - nothing would be left
	 * to recognize it by. The title itself stays required either way: the screen reader and the
	 * browser tooltip live off it.
	 */
	private function validateAppearance(array $element, Kind $kind): Result
	{
		$result = new Result();

		$appearance = [];

		if (array_key_exists('showTitle', $element))
		{
			if (!is_bool($element['showTitle']))
			{
				return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_SHOW_TITLE));
			}

			$appearance['showTitle'] = $element['showTitle'];
		}

		if (array_key_exists('icon', $element))
		{
			$icon = is_string($element['icon']) ? IconType::tryFrom($element['icon']) : null;
			if ($icon === null)
			{
				return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_ICON));
			}

			$appearance['icon'] = $icon->value;
		}

		if (($appearance['showTitle'] ?? true) === false && !isset($appearance['icon']))
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_HIDDEN_TITLE_WITHOUT_ICON));
		}

		$sizeResult = $this->validateChipSize($element, $kind);
		if (!$sizeResult->isSuccess())
		{
			return $sizeResult;
		}

		return $result->setResult($appearance + $sizeResult->getResult());
	}

	/**
	 * The size shapes a chip and means nothing on a button: the design system draws a button by a
	 * size of its own. Naming it on a button is turned down rather than dropped - the sender is
	 * building an element they will not get, and a silent drop would send them looking for the
	 * reason in the clients.
	 *
	 * A size outside the set is refused the way a chip design is, and for the same reason: the set
	 * holds what was checked, and a silent correction would hide the ask from whoever made it.
	 */
	private function validateChipSize(array $element, Kind $kind): Result
	{
		$result = new Result();

		if (!array_key_exists('size', $element))
		{
			return $result->setResult([]);
		}

		if ($kind !== Kind::Chip)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_CHIP_SIZE_ON_BUTTON));
		}

		$size = is_string($element['size']) ? ChipSize::tryFrom($element['size']) : null;
		if ($size === null)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_CHIP_SIZE));
		}

		return $result->setResult(['size' => $size->value]);
	}

	private function validateTitle(array $element): Result
	{
		$result = new Result();

		$title = $element['title'] ?? null;
		if (!is_string($title) || trim($title) === '')
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_TITLE_EMPTY));
		}

		if (mb_strlen($title) > self::MAX_TITLE_LENGTH)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_TITLE_LENGTH_EXCEEDED));
		}

		return $result->setResult($title);
	}

	/**
	 * The cap counts the list as it came, before duplicates are dropped: a sender that repeats the
	 * same id two hundred times is still building a list the contract does not allow.
	 */
	private function validateForUsers(mixed $forUsers): Result
	{
		$result = new Result();

		if (!is_array($forUsers))
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_FOR_USERS));
		}

		if (count($forUsers) > self::MAX_FOR_USERS)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_FOR_USERS_EXCEEDED));
		}

		$userIds = [];
		foreach ($forUsers as $userId)
		{
			if (!$this->isUserId($userId))
			{
				return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_FOR_USERS));
			}

			$userIds[] = (int)$userId;
		}

		return $result->setResult(array_values(array_unique($userIds)));
	}

	private function isUserId(mixed $userId): bool
	{
		if (is_int($userId))
		{
			return $userId > 0;
		}

		// The cast has to keep the value, not only the spelling: leading zeros are fine, while an
		// overflowing "99999999999999999999" becomes PHP_INT_MAX - a different user than the one named.
		//
		// The ctype extension is not installed on the portals, so a call to ctype_digit() would end the
		// request with a fatal. The predicate below is the canonical replacement, kept in that exact
		// form on purpose - `/D` holds a trailing newline out and the strict `=== 1` turns an internal
		// PCRE error into "not digits". Here the newline is already held out by the value check on the
		// last line, so `/D` is the second lock rather than the first:
		// @see .ai/knowledge-base/notes/backend/references/php-extensions.md
		return is_string($userId)
			&& preg_match('/^[0-9]+$/D', $userId) === 1
			&& (int)$userId > 0
			&& (string)(int)$userId === ltrim($userId, '0')
		;
	}

	/**
	 * A button corrects an unknown design, a chip turns it down. The sets differ for a reason: the
	 * chip one holds only what the mobile app was checked on, and the app draws a filled chip for
	 * everything else - a correction here would hand that drift to a person instead of the sender.
	 */
	private function validateDesign(array $element, Kind $kind): Result
	{
		$result = new Result();

		$design = (string)($element['design'] ?? '');

		if ($kind !== Kind::Chip)
		{
			return $result->setResult((Design::tryFrom($design) ?? Design::Filled)->value);
		}

		if ($design === '')
		{
			return $result->setResult(ChipDesign::Filled->value);
		}

		$chipDesign = ChipDesign::tryFrom($design);
		if ($chipDesign === null)
		{
			return $result->addError(new BuilderError(BuilderError::FOOTER_INVALID_CHIP_DESIGN));
		}

		return $result->setResult($chipDesign->value);
	}
}
