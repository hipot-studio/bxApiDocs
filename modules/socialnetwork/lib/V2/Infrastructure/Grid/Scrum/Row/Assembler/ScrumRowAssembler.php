<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Row\Assembler;

use Bitrix\Main\Grid\Row\Assembler\Field\NumberFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Row\Assembler\Field\CounterFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Row\Assembler\Field\ScrumNameFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Scrum\Row\Assembler\Field\ScrumRoleFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\ActivityDateFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\DateFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\MembersFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\PinFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\PrivacyTypeFieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field\TagsFieldAssembler;

class ScrumRowAssembler extends RowAssembler
{
	public function __construct(
		array $visibleColumnIds,
		private readonly string $gridId = '',
		private readonly int $contextUserId = 0,
	)
	{
		parent::__construct($visibleColumnIds);
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new NumberFieldAssembler(false, ['ID', 'NUMBER_OF_MEMBERS']),
			new ScrumNameFieldAssembler(['NAME']),
			new ActivityDateFieldAssembler(['ACTIVITY_DATE']),
			new DateFieldAssembler(['DATE_CREATE', 'DATE_ACTIVITY', 'DATE_RELATION', 'DATE_VIEW']),
			new PrivacyTypeFieldAssembler(['PRIVACY_TYPE']),
			new MembersFieldAssembler(['MEMBERS'], $this->gridId, Type::Scrum->value),
			new ScrumRoleFieldAssembler(['ROLE']),
			new TagsFieldAssembler(['TAGS'], $this->gridId, Type::Scrum->value),
			new PinFieldAssembler(['NAME'], Type::Scrum->value),
			new CounterFieldAssembler(['ACTIVITY_DATE', 'DATE_ACTIVITY'], $this->contextUserId),
		];
	}
}
