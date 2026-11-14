<?php declare(strict_types=1);

namespace Bitrix\AI\ShareRole\Request;

use Bitrix\AI\BaseRequest;
use Bitrix\AI\Exception\ErrorCollectionException;
use Bitrix\AI\Exception\ValidateException;
use Bitrix\AI\ShareRole\Dto\ChangeActivityByCodesDto;
use Bitrix\AI\Validator\BaseValidator;
use Bitrix\AI\Validator\RoleValidator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;

/**
 * @method ChangeActivityByCodesDto getData(HttpRequest $request, CurrentUser $currentUser = null)
 * @property ?ChangeActivityByCodesDto $object
 */
class ChangeActivityByCodesRequest extends BaseRequest
{
	public const ERROR_NOT_AUTHOR = 'ROLE_ACTIVITY_NOT_AUTHOR';

	public function __construct(
		protected BaseValidator $baseValidator,
		protected RoleValidator $roleValidator
	)
	{
	}

	protected function getObjectWithData(): ChangeActivityByCodesDto
	{
		$dto = new ChangeActivityByCodesDto();
		$dto->userId = (int)$this->currentUser?->getId();
		$dto->roleCodes = $this->getArray('selectedShareRolesCodes');
		$this->baseValidator->isNotEmptyArray($dto->roleCodes, 'selectedShareRolesCodes');

		$dto->roleCodes = array_unique($dto->roleCodes);

		$dto->roleIds = $this->roleValidator->getRoleByCodesNotSystems(
			$dto->roleCodes,
			'selectedShareRolesCodes'
		);

		$this->roleValidator->accessOnRoles($dto->roleIds, 'selectedShareRolesCodes', $dto->userId);
		try
		{
			$this->roleValidator->checkRolesAuthor($dto->roleIds, 'selectedShareRolesCodes', $dto->userId);
		}
		catch (ValidateException $exception)
		{
			throw new ErrorCollectionException(new ErrorCollection([
				new Error(
					sprintf('%s: %s', $exception->getFieldName(), $exception->getError()),
					self::ERROR_NOT_AUTHOR,
				),
			]));
		}
		$dto->needActivate = $this->getBool('needActivate', '1');

		return $dto;
	}
}
