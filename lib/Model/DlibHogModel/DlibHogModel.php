<?php
/**
 * @copyright Copyright (c) 2020, Matias De lellis <mati86dl@gmail.com>
 * @copyright Copyright (c) 2018, Branko Kokanovic <branko@kokanovic.org>
 *
 * @author Branko Kokanovic <branko@kokanovic.org>
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

namespace OCA\FaceRecognition\Model\DlibHogModel;

use OCP\IDBConnection;

use OCA\FaceRecognition\Helper\MemoryLimits;

use OCA\FaceRecognition\Service\FileService;
use OCA\FaceRecognition\Service\ModelService;
use OCA\FaceRecognition\Service\SettingsService;

use OCA\FaceRecognition\Model\IModel;

class DlibHogModel implements IModel {

	/*
	 * Model files.
	 */
	const FACE_MODEL_ID = 3;
	const FACE_MODEL_NAME = "DlibHog";
	const FACE_MODEL_DESC = "Dlib HOG Model which needs lower requirements";

	/** Relationship between image size and memory consumed */
	const MEMORY_AREA_RELATIONSHIP = 1 * 1024;

	const FACE_MODEL_BZ2_URLS = [
		'https://github.com/davisking/dlib-models/raw/4af9b776281dd7d6e2e30d4a2d40458b1e254e40/shape_predictor_5_face_landmarks.dat.bz2',
		'https://github.com/davisking/dlib-models/raw/2a61575dd45d818271c085ff8cd747613a48f20d/dlib_face_recognition_resnet_model_v1.dat.bz2'
	];

	const FACE_MODEL_FILES = [
		'shape_predictor_5_face_landmarks.dat',
		'dlib_face_recognition_resnet_model_v1.dat'
	];

	const I_MODEL_PREDICTOR = 0;
	const I_MODEL_RESNET = 1;

	/** @var \FaceLandmarkDetection */
	private $fld;

	/** @var \FaceRecognition */
	private $fr;

	/** @var IDBConnection */
	private $connection;

	/** @var FileService */
	private $fileService;

	/** @var ModelService */
	private $modelService;

	/** @var SettingsService */
	private $settingsService;


	/**
	 * DlibCnnModel __construct.
	 *
	 * @param IDBConnection $connection
	 * @param FileService $fileService
	 * @param ModelService $modelService
	 * @param SettingsService $settingsService
	 */
	public function __construct(IDBConnection   $connection,
	                            FileService     $fileService,
	                            ModelService    $modelService,
	                            SettingsService $settingsService)
	{
		$this->connection       = $connection;
		$this->fileService      = $fileService;
		$this->modelService     = $modelService;
		$this->settingsService  = $settingsService;
	}

	public function getId(): int {
		return static::FACE_MODEL_ID;
	}

	public function getName(): string {
		return static::FACE_MODEL_NAME;
	}

	public function getDescription(): string {
		return static::FACE_MODEL_DESC;
	}

	public function isInstalled(): bool {
		if (!$this->modelService->modelFileExists($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]))
			return false;
		if (!$this->modelService->modelFileExists($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_RESNET]))
			return false;
		return true;
	}

	public function meetDependencies(): bool {
		return extension_loaded('pdlib') &&
		       version_compare(phpversion('pdlib'), '1.0.1', '>=');
	}

	public function getMaximumArea(): int {
		return intval(MemoryLimits::getAvailableMemory()/self::MEMORY_AREA_RELATIONSHIP);
	}

	public function install() {
		if ($this->isInstalled()) {
			return;
		}

		// Create main folder where install models.
		$this->modelService->prepareModelFolder($this->getId());

		/* Download and install models */
		$predictorModelBz2 = $this->fileService->downloaldFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_PREDICTOR]);
		$this->fileService->bunzip2($predictorModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]));

		$resnetModelBz2 = $this->fileService->downloaldFile(static::FACE_MODEL_BZ2_URLS[self::I_MODEL_RESNET]);
		$this->fileService->bunzip2($resnetModelBz2, $this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_RESNET]));

		/* Clean temporary files */
		$this->fileService->clean();

		// Insert on database and enable it
		$qb = $this->connection->getQueryBuilder();
		$query = $qb->select($qb->createFunction('COUNT(' . $qb->getColumnName('id') . ')'))
			->from('facerecog_models')
			->where($qb->expr()->eq('id', $qb->createParameter('id')))
			->setParameter('id', $this->getId());
		$resultStatement = $query->execute();
		$data = $resultStatement->fetch(\PDO::FETCH_NUM);
		$resultStatement->closeCursor();

		if ((int)$data[0] <= 0) {
			$query = $this->connection->getQueryBuilder();
			$query->insert('facerecog_models')
			->values([
				'id' => $query->createNamedParameter($this->getId()),
				'name' => $query->createNamedParameter($this->getName()),
				'description' => $query->createNamedParameter($this->getDescription())
			])
			->execute();
		}
	}

	public function open() {
		$this->fld = new \FaceLandmarkDetection($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_PREDICTOR]));
		$this->fr = new \FaceRecognition($this->modelService->getFileModelPath($this->getId(), static::FACE_MODEL_FILES[self::I_MODEL_RESNET]));
	}

	public function detectFaces(string $imagePath): array {
		$faces_detected = dlib_face_detection($imagePath);
		// To improve clustering a confidence value is needed, which this model does not provide
		return array_map (function (array $face) { $face['detection_confidence'] = 1.0; return $face; }, $faces_detected);
	}

	public function detectLandmarks(string $imagePath, array $rect): array {
		return $this->fld->detect($imagePath, $rect);
	}

	public function computeDescriptor(string $imagePath, array $landmarks): array {
		return $this->fr->computeDescriptor($imagePath, $landmarks);
	}

}
