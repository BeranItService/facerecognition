<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
 *
 * @author Matias De lellis <mati86dl@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FaceRecognition\Model;

use OCP\IUser;
use OCP\IUserManager;

use OCA\FaceRecognition\Model\IModel;

use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn68Model;
use OCA\FaceRecognition\Model\DlibCnnModel\DlibCnn5Model;

use OCA\FaceRecognition\Model\DlibHogModel\DlibHogModel;

class ModelManager {

	/** There is no default model. This is used by tests */
	const DEFAULT_FACE_MODEL_ID = 1;

	/** @var IUserManager */
	private $userManager;

	/** @var SettingsService */
	private $settingsService;

	/** @var DlibCnn5Model */
	private $dlibCnn5Model;

	/** @var DlibCnn68Model */
	private $dlibCnn68Model;

	/** @var DlibHogModel */
	private $dlibHogModel;

	/**
	 * @patam IUserManager $userManager
	 * @param SettingsService $settingsService
	 * @param DlibCnn5Model $dlibCnn5Model
	 * @param DlibCnn68Model $dlibCnn68Model
	 * @param DlibHogModel $dlibHogModel
	 */
	public function __construct(IUserManager    $userManager,
	                            SettingsService $settingsService,
	                            DlibCnn5Model   $dlibCnn5Model,
	                            DlibCnn68Model  $dlibCnn68Model,
	                            DlibHogModel    $dlibHogModel)
	{
		$this->userManager     = $userManager;
		$this->settingsService = $settingsService;

		$this->dlibCnn5Model   = $dlibCnn5Model;
		$this->dlibCnn68Model  = $dlibCnn68Model;
		$this->dlibHogModel    = $dlibHogModel;
	}

	/**
	 * @param int $version model version
	 * @return IModel|null
	 */
	public function getModel(int $version): ?IModel {
		switch ($version) {
			case 1:
				$model = $this->dlibCnn5Model;
				break;
			case 2:
				$model = $this->dlibCnn68Model;
				break;
			case 3:
				$model = $this->dlibHogModel;
				break;
			default:
				$model = null;
				break;
		}
		return $model;
	}

	/**
	 * @return IModel|null
	 */
	public function getCurrentModel(): ?IModel {
		$modelId = $this->settingsService->getCurrentFaceModel();
		return $this->getModel($modelId);
	}

	/**
	 * @return IModel[]
	 */
	public function getAllModels(): array {
		return [
			$this->dlibCnn5Model,
			$this->dlibCnn68Model,
			$this->dlibHogModel
		];
	}

	/**
	 * Set default model to use
	 * @param int $version model version
	 * @return bool true if successful. False otherwise
	 */
	public function setDefault(int $version): bool {
		$model = $this->getModel($version);
		if (is_null($model) || !$model->isInstalled() || !$model->meetDependencies())
			return false;

		if ($this->settingsService->getCurrentFaceModel() !== $model->getId()) {
			$this->settingsService->setCurrentFaceModel($model->getId());
		}
		$this->userManager->callForAllUsers(function (IUser $user) {
			$this->settingsService->setUserFullScanDone(false, $user->getUID());
		});

		return true;
	}

}