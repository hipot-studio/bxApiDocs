<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\ContentType;
use Bitrix\Main\Engine\ActionFilter\FilterType;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\EnablePrefilters;
use Bitrix\Socialnetwork\Collab\Controller\Filter\IntranetUserFilter;
use Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;
use Bitrix\Socialnetwork\V2\Public\Command\Convert\ConvertToProjectCommand;
use Bitrix\Socialnetwork\V2\Public\Dto;
use Bitrix\Socialnetwork\V2\Internal\Entity;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectResponse;

class Convert extends BaseController
{
	public function getAutoWiredParameters()
	{
		return [
			new ExactParameter(
				Dto\Project\Convert::class,
				'convertDto',
				fn (string $className, array $group): ?Entity\EntityInterface
				=> $this->getWithAccess($this, 'convertDto', Dto\Project\Convert::mapFromArray($group)),
			),
			new ExactParameter(
				Dto\Project\Convert::class,
				'convertDto',
				fn (string $className, int $groupId): ?Entity\EntityInterface
				=> $this->getWithAccess($this, 'convertDto', new Dto\Project\Convert(id: $groupId)),
			),
		];
	}

	/**
	 * @ajaxAction socialnetwork.V2.Convert.convertToProject
	 */
	#[ContentType(type: FilterType::DisablePrefilter)]
	#[EnablePrefilters([
		new IntranetUserFilter(),
	])]
	public function convertToProjectAction(
		#[Permission\Convert]
		Dto\Project\Convert $convertDto,
		ProjectProvider $projectProvider,
	): ?ProjectResponse
	{
		$result = (new ConvertToProjectCommand(
			groupId: $convertDto->getId(),
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $projectProvider->getById($convertDto->getId());
	}
}
