<?php
namespace TYPO3\CMS\DamFalmigration\Service;

/**
 *  Copyright notice
 *
 *  (c) 2012 Benjamin Mack <benni@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is free
 *  software; you can redistribute it and/or modify it under the terms of the
 *  GNU General Public License as published by the Free Software Foundation;
 *  either version 2 of the License, or (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful, but
 *  WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 *  or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 *  more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 */
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Service to Migrate Records
 * Finds all DAM records that have not been migrated yet
 * and adds a DB field "_migratedfaluid" to each DAM record
 * to connect the DAM and FAL DB records
 *
 * currently it only works for files within the fileadmin
 * FILES DO NOT GET MOVED somewhere else
 *
 * @author Benjamin Mack <benni@typo3.org>
 */
class MigrateService extends AbstractService {

	/**
	 * @var integer
	 */
	protected $currentTime;

	/**
	 * how to map cols for meta data
	 * These cols are always available since TYPO3 6.2
	 *
	 * @var array
	 */
	protected $metaColMapping = array(
		'title' => 'title',
		'hpixels' => 'width',
		'vpixels' => 'height',
		'description' => 'description',
		'alt_text' => 'alternative',
		'categories' => 'categories',
	);

	/**
	 * how to map cols for meta data
	 * These additional cols are available only if ext:filemetadata is installed
	 *
	 * @var array
	 */
	protected $additionalMetaColMapping = array(
		'creator' => 'creator',
		'keywords' => 'keywords',
		'caption' => 'caption',
		'language' => 'language',
		'pages' => 'pages',
		'publisher' => 'publisher',
		'loc_country' => 'location_country',
		'loc_city' => 'location_city',
	);

	/**
	 * @var array
	 */
	protected $columnMapping = array();

	/**
	 * saves the amount of files which could not be found in storage
	 *
	 * @var integer
	 */
	protected $amountOfFilesNotFound = 0;

	/**
	 * main function, needs to return TRUE or FALSE in order to tell
	 * the scheduler whether the task went through smoothly
	 *
	 * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
	 * @throws \Exception
	 * @return FlashMessage
	 */
	public function execute() {
		$this->controller->headerMessage(LocalizationUtility::translate('connectDamRecordsWithSysFileCommand', 'dam_falmigration', array($this->storageObject->getName())));
		if (!$this->isTableAvailable('tx_dam')) {
			return $this->getResultMessage('damTableNotFound');
		}

		$result = $this->execSelectNotMigratedDamRecordsQuery();

		$counter = 0;
		$total = $this->database->sql_num_rows($result);
		$this->controller->infoMessage('Found ' . $total . ' DAM records without a connection to a sys_file entry');

		$this->initializeColumnMapping();

		while ($damRecord = $this->database->sql_fetch_assoc($result)) {
			$this->currentTime = time();
			$counter++;
			if ($this->isValidDirectory($damRecord)) {
				try {
					$fullFileName = $this->getFullFileName($damRecord);

					$fileObject = $this->storageObject->getFile($fullFileName);
					if ($fileObject instanceof \TYPO3\CMS\Core\Resource\File) {
						if ($fileObject->isMissing()) {
							$this->controller->warningMessage('FAL did not find any file resource for DAM record. DAM uid: ' . $damRecord['uid'] . ': "' . $fullFileName . '"');
							continue;
						}
						$this->controller->message(number_format(100 * ($counter / $total), 1) . '% of ' . $total . ' id: ' . $damRecord['uid'] . ': ' . $fullFileName);
						$this->migrateFileFromDamToFal($damRecord, $fileObject);
						$this->amountOfMigratedRecords++;
					}
				} catch (\Exception $e) {
					// If file is not found
					$this->setDamFileMissingByUid($damRecord['uid']);
					$this->controller->warningMessage($e->getMessage());
					$this->amountOfFilesNotFound++;
					continue;
				}
			}
		}
		$this->database->sql_free_result($result);

		$this->controller->message(
			'Not migrated dam records at start of task: ' . $total . '. Migrated files after task: ' . $this->amountOfMigratedRecords . '. Files not found: ' . $this->amountOfFilesNotFound . '.'
		);

		return $this->getResultMessage();
	}

	/**
	 * Checks if file identifier is in a valid directory
	 *
	 * @param array $damRecord
	 *
	 * @return bool
	 */
	protected function isValidDirectory(array $damRecord) {
		return GeneralUtility::isFirstPartOfStr($this->getFileIdentifier($damRecord), 'fileadmin/');
	}

	/**
	 * Count the dam records which have not been migrated yet
	 *
	 * @return integer
	 */
	public function countNotMigratedDamRecordsQuery() {
		$row = $this->database->exec_SELECTgetSingleRow(
			'COUNT(*) as total',
			'tx_dam LEFT JOIN sys_file ON (tx_dam._migratedfaluid = sys_file.uid)',
			'sys_file.uid IS NULL AND
			 tx_dam.deleted = 0 AND
			 tx_dam.file_path LIKE "' . $this->storageBasePath . '%" AND
			 tx_dam._missingfile = 0'
		);
		return (int)$row['total'];
	}

	/**
	 * Select the dam records which have not been migrated yet
	 *
	 * @return \mysqli_result
	 */
	protected function execSelectNotMigratedDamRecordsQuery() {
		return $this->database->exec_SELECTquery(
			'tx_dam.*',
			'tx_dam LEFT JOIN sys_file ON (tx_dam._migratedfaluid = sys_file.uid)',
			'sys_file.uid IS NULL AND
			 tx_dam.deleted = 0 AND
			 tx_dam.file_path LIKE "' . $this->storageBasePath . '%" AND
			 tx_dam._missingfile = 0',
			'',
			'',
			(int)$this->getRecordLimit()
		);
	}

	/**
	 * Mark a dam file as missing
	 *
	 * @return void
	 */
	protected function setDamFileMissingByUid($uid) {
		$this->database->exec_UPDATEquery(
			'tx_dam',
			'uid = ' . (int)$uid,
			array ('_missingfile' => 1)
		);
	}

	/**
	 * migrate file from dam record to fal system
	 *
	 * @param array $damRecord
	 * @param \TYPO3\CMS\Core\Resource\File $fileObject
	 *
	 * @throws \Exception
	 * @return void
	 */
	protected function migrateFileFromDamToFal(array $damRecord, \TYPO3\CMS\Core\Resource\File $fileObject) {
		// in getProperties() we don't have the required UID of metadata record
		// if no metadata record is available it will automatically created within FAL
		$metadataRecord = $fileObject->_getMetaData();

		if (is_array($metadataRecord)) {
			// update existing record
			$this->database->exec_UPDATEquery(
				'sys_file_metadata',
				'uid = ' . $metadataRecord['uid'],
				$this->createArrayForUpdateInsertSysFileRecord($damRecord)
			);

			// add the uid of the FAL record to the original DAM record
			$this->database->exec_UPDATEquery(
				'tx_dam',
				'uid = ' . $damRecord['uid'],
				array('_migratedfaluid' => $fileObject->getUid())
			);
		}
	}

	/**
	 * create an array for insert or updating the sys_file record
	 *
	 * @param array $damRecord
	 *
	 * @return array
	 */
	protected function createArrayForUpdateInsertSysFileRecord(array $damRecord) {
		$updateData = array(
			'tstamp' => $this->currentTime,
		);

		foreach ($this->columnMapping as $damColName => $metaColName) {
			$updateData[$metaColName] = $damRecord[$damColName];
		}

		return $updateData;
	}

	/**
	 * initialize column mapping for insert or updating the sys_file record
	 *
	 * @return void
	 */
	protected function initializeColumnMapping() {
		// add always available cols for filemetadata
		foreach ($this->metaColMapping as $damColName => $metaColName) {
			$this->columnMapping[$damColName] = $metaColName;
		}

		// add additional cols if ext:for filemetadata is installed
		if (ExtensionManagementUtility::isLoaded('filemetadata')) {
			foreach ($this->additionalMetaColMapping as $damColName => $metaColName) {
				$this->columnMapping[$damColName] = $metaColName;
			}
		}
	}
}
