<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Design;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Type;

class Buttons extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (!is_array($field) || empty($field))
		{
			return $result->setResult([]);
		}

		$buttonsResult = [];
		foreach ($field as $buttons)
		{
			if (!is_array($buttons))
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_BUTTONS_FIELD));
			}

			$result = $this->validateButtonLine($buttons);
			if (!$result->isSuccess())
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_BUTTONS_FIELD));
			}

			$buttonsResult[] = $result->getResult();
		}

		return $result->setResult($buttonsResult);
	}

	protected function validateButtonLine(array $buttons): Result
	{
		$result = new Result();

		$buttonsResult = [];
		foreach ($buttons as $button)
		{
			if (!is_array($button))
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_BUTTON_FIELD));
			}

			$result = $this->validateButton($button);
			if (!$result->isSuccess())
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_BUTTON_FIELD));
			}

			$buttonsResult[] = $result->getResult();
		}

		return $result->setResult($buttonsResult);
	}

	protected function validateButton(array $button): Result
	{
		$buttonType = Type::tryFrom((string)($button['type'] ?? ''));
		return match ($buttonType)
		{
			Type::LinkButton => $this->validateByLink($button),
			Type::EventButton => $this->validateByEvent($button),
			default => (new Result())->addError(new BuilderError(BuilderError::WRONG_BUTTON_TYPE)),
		};
	}

	protected function validateByLink(array $button): Result
	{
		$result = $this->validateTitle($button);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$title = $result->getResult();

		$url = $button['url'] ?? null;
		if (!is_string($url))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_URL_FIELD));
		}
		if ($url === '')
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_URL_FIELD));
		}
		if (!$this->isAllowedUrl($url))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_URL_FIELD));
		}

		return $result->setResult([
			'title' => $title,
			'type' => Type::LinkButton->value,
			'design' => $this->validateDesign($button),
			'url' => $url,
		]);
	}

	protected function validateByEvent(array $button): Result
	{
		$result = $this->validateTitle($button);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$title = $result->getResult();

		$actionId = $button['actionId'] ?? null;
		if (!is_string($actionId))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_ACTION_ID_FIELD));
		}
		if (empty($actionId))
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_ACTION_ID_FIELD));
		}

		return $result->setResult([
			'title' => $title,
			'type' => Type::EventButton->value,
			'design' => $this->validateDesign($button),
			'actionId' => $actionId,
			'actionParams' => $this->validateActionParam($button),
		]);
	}

	protected function validateActionParam(array $button): ?array
	{
		$actionParam = $button['actionParams'] ?? null;
		if (!is_array($actionParam))
		{
			return null;
		}

		return $actionParam;
	}

	protected function validateDesign(array $button): string
	{
		$design = Design::tryFrom((string)($button['design'] ?? ''));
		if ($design === null)
		{
			$design = Design::Filled;
		}

		return $design->value;
	}

	protected function validateTitle(array $button): Result
	{
		$result = new Result();
		$title = $button['title'] ?? null;

		if (!is_string($title))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_TITLE_FIELD));
		}
		if ($title === '')
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_TITLE_FIELD));
		}

		return $result->setResult($title);
	}
}
