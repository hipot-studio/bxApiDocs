<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Factory;

use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\AbstractField;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Blocks;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Color;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Elements;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Icon;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Id;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\ImageUrl;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Rows;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Size;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Status;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Text;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Field\Type;
use Bitrix\Im\V2\Message\Builder\Entity\Field;

class FieldFactory
{
	public function create(string $type): ?AbstractField
	{
		return match (Field::tryFrom($type))
		{
			Field::Id => Id::getInstance(),
			Field::Type => Type::getInstance(),
			Field::Text => Text::getInstance(),
			Field::Status => Status::getInstance(),
			Field::Size => Size::getInstance(),
			Field::Color => Color::getInstance(),
			Field::Elements => Elements::getInstance(),
			Field::Rows => Rows::getInstance(),
			Field::Icon => Icon::getInstance(),
			Field::ImageUrl => ImageUrl::getInstance(),
			Field::Blocks => Blocks::getInstance(),
			default => null,
		};
	}
}
