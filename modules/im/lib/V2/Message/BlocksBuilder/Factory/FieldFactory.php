<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Factory;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\AbstractField;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Blocks;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Color;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Elements;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Fold;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Icon;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Id;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\ImageUrl;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Rows;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Size;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Sources;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Status;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Text;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Title;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Type;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Field;

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
			Field::Fold => Fold::getInstance(),
			Field::Title => Title::getInstance(),
			Field::Sources => Sources::getInstance(),
			default => null,
		};
	}
}
