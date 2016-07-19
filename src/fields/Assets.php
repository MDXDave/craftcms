<?php
/**
 * @link      http://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   http://craftcms.com/license
 */

namespace craft\app\fields;

use Craft;
use craft\app\base\Element;
use craft\app\base\ElementInterface;
use craft\app\elements\Asset;
use craft\app\elements\db\AssetQuery;
use craft\app\elements\db\ElementQuery;
use craft\app\errors\AssetConflictException;
use craft\app\errors\InvalidSubpathException;
use craft\app\errors\VolumeObjectNotFoundException;
use craft\app\helpers\Assets as AssetsHelper;
use craft\app\helpers\Io;
use craft\app\helpers\StringHelper;
use craft\app\models\VolumeFolder;
use craft\app\web\UploadedFile;
use yii\helpers\FileHelper;

/**
 * Assets represents an Assets field.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Assets extends BaseRelationField
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName()
    {
        return Craft::t('app', 'Assets');
    }

    /**
     * @inheritdoc
     * @return Asset
     */
    protected static function elementType()
    {
        return Asset::className();
    }

    /**
     * @inheritdoc
     */
    public static function defaultSelectionLabel()
    {
        return Craft::t('app', 'Add an asset');
    }

    // Properties
    // =========================================================================

    /**
     * @var boolean Whether related assets should be limited to a single folder
     */
    public $useSingleFolder;

    /**
     * @var integer The asset source ID that files should be uploaded to by default (only used if [[useSingleFolder]] is false)
     */
    public $defaultUploadLocationSource;

    /**
     * @var string The subpath that files should be uploaded to by default (only used if [[useSingleFolder]] is false)
     */
    public $defaultUploadLocationSubpath;

    /**
     * @var integer The asset source ID that files should be restricted to (only used if [[useSingleFolder]] is true)
     */
    public $singleUploadLocationSource;

    /**
     * @var string The subpath that files should be restricted to (only used if [[useSingleFolder]] is true)
     */
    public $singleUploadLocationSubpath;

    /**
     * @var boolean Whether the available assets should be restricted to [[allowedKinds]]
     */
    public $restrictFiles;

    /**
     * @var array The file kinds that the field should be restricted to (only used if [[restrictFiles]] is true)
     */
    public $allowedKinds;

    /**
     * @inheritdoc
     */
    protected $allowLargeThumbsView = true;

    /**
     * @inheritdoc
     */
    protected $inputJsClass = 'Craft.AssetSelectInput';

    /**
     * @inheritdoc
     */
    protected $inputTemplate = '_components/fieldtypes/Assets/input';

    /**
     * Uploaded files that failed validation.
     *
     * @var UploadedFile[]
     */
    private $_failedFiles = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        // Create a list of folder options for the main Source setting, and source options for the upload location
        // settings.
        $folderOptions = [];
        $sourceOptions = [];

        $class = self::elementType();

        foreach ($class::getSources() as $key => $source) {
            if (!isset($source['heading'])) {
                $folderOptions[] = [
                    'label' => $source['label'],
                    'value' => $key
                ];
            }
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $source) {
            $sourceOptions[] = [
                'label' => $source->name,
                'value' => $source->id
            ];
        }

        $fileKindOptions = [];

        foreach (Io::getFileKinds() as $value => $kind) {
            $fileKindOptions[] = ['value' => $value, 'label' => $kind['label']];
        }

        $namespace = Craft::$app->getView()->getNamespace();
        $isMatrix = (strncmp($namespace, 'types[Matrix][blockTypes][',
                26) === 0);

        return Craft::$app->getView()->renderTemplate('_components/fieldtypes/Assets/settings',
            [
                'allowLimit' => $this->allowLimit,
                'folderOptions' => $folderOptions,
                'sourceOptions' => $sourceOptions,
                'targetLocaleFieldHtml' => $this->getTargetLocaleFieldHtml(),
                'viewModeFieldHtml' => $this->getViewModeFieldHtml(),
                'field' => $this,
                'displayName' => self::displayName(),
                'fileKindOptions' => $fileKindOptions,
                'isMatrix' => $isMatrix,
                'defaultSelectionLabel' => static::defaultSelectionLabel(),
            ]);
    }

    /**
     * @inheritdoc
     */
    public function getInputHtml($value, $element)
    {
        try {
            return parent::getInputHtml($value, $element);
        } catch (InvalidSubpathException $e) {
            return '<p class="warning">'.
            '<span data-icon="alert"></span> '.
            Craft::t('This field’s target subfolder path is invalid: {path}', [
                'path' => '<code>'.$this->singleUploadLocationSubpath.'</code>'
            ]).
            '</p>';
        }
    }

    /**
     * @inheritdoc
     */
    public function beforeElementSave(ElementInterface $element)
    {
        /** @var Element $element */
        $incomingFiles = [];

        /** @var AssetQuery $newValue */
        $query = $this->getElementValue($element);
        $value = !empty($query->id) ? $query->id : [];

        // Grab data strings
        if (isset($value['data']) && is_array($value['data'])) {
            foreach ($value['data'] as $index => $dataString) {
                if (preg_match('/^data:(?<type>[a-z0-9]+\/[a-z0-9]+);base64,(?<data>.+)/i',
                    $dataString, $matches)) {
                    $type = $matches['type'];
                    $data = base64_decode($matches['data']);

                    if (!$data) {
                        continue;
                    }

                    if (!empty($value['filenames'][$index])) {
                        $filename = $value['filenames'][$index];
                    } else {
                        $extensions = FileHelper::getExtensionsByMimeType($type);

                        if (empty($extensions)) {
                            continue;
                        }

                        $filename = 'Uploaded_file.'.reset($extensions);
                    }

                    $incomingFiles[] = [
                        'filename' => $filename,
                        'data' => $data,
                        'type' => 'data'
                    ];
                }
            }
        }

        // Remove these so they don't interfere.
        if (isset($value['data']) || isset($value['filenames'])) {
            unset($value['data'], $value['filenames']);
        }

        // See if we have uploaded file(s).
        $contentPostLocation = $this->getContentPostLocation($element);

        if ($contentPostLocation) {
            $files = UploadedFile::getInstancesByName($contentPostLocation);

            foreach ($files as $file) {
                $incomingFiles[] = [
                    'filename' => $file->name,
                    'location' => $file->tempName,
                    'type' => 'upload'
                ];
            }
        }

        if (isset($this->restrictFiles) && !empty($this->restrictFiles) && !empty($this->allowedKinds)) {
            $allowedExtensions = $this->_getAllowedExtensions($this->allowedKinds);
        } else {
            $allowedExtensions = false;
        }

        if (is_array($allowedExtensions)) {
            foreach ($incomingFiles as $file) {
                $extension = StringHelper::toLowerCase(Io::getExtension($file['filename']));

                if (!in_array($extension, $allowedExtensions)) {
                    $this->_failedFiles[] = $file['filename'];
                }
            }
        }

        if (!empty($this->_failedFiles)) {
            return;
        }

        // If we got here either there are no restrictions or all files are valid so let's turn them into Assets
        $assetIds = [];
        $targetFolderId = $this->_determineUploadFolderId($element);

        if (!empty($targetFolderId)) {
            foreach ($incomingFiles as $file) {
                $tempPath = AssetsHelper::getTempFilePath($file['filename']);
                if ($file['type'] == 'upload') {
                    move_uploaded_file($file['location'], $tempPath);
                }
                if ($file['type'] == 'data') {
                    Io::writeToFile($tempPath, $file['data']);
                }

                $folder = Craft::$app->getAssets()->getFolderById($targetFolderId);
                $asset = new Asset();
                $asset->title = StringHelper::toTitleCase(Io::getFilename($file['filename'], false));
                $asset->newFilePath = $tempPath;
                $asset->filename = $file['filename'];
                $asset->folderId = $targetFolderId;
                $asset->volumeId = $folder->volumeId;
                Craft::$app->getAssets()->saveAsset($asset);

                $assetIds[] = $asset->id;
                Io::deleteFile($tempPath, true);
            }

            $assetIds = array_unique(array_merge($value, $assetIds));

            // Make it look like the actual POST data contained these file IDs as well,
            // so they make it into entry draft/version data
            $element->setRawPostValueForField($this->handle, $assetIds);

            /** @var AssetQuery $newValue */
            $newValue = $this->prepareValue($assetIds, $element);
            $this->setElementValue($element, $newValue);
        }
    }

    /**
     * @inheritdoc
     */
    public function afterElementSave(ElementInterface $element)
    {
        $value = $this->getElementValue($element);
        $assetsToMove = [];

        if ($value instanceof AssetQuery) {
            $value = $value->all();
        }

        if (is_array($value) && count($value)) {
            if ($this->useSingleFolder) {
                $targetFolder = $this->_resolveVolumePathToFolder(
                    $this->singleUploadLocationSource,
                    $this->singleUploadLocationSubpath,
                    $element
                );

                // Move only those Assets that have had their folder changed.
                foreach ($value as $asset) {
                    if ($targetFolder->id != $asset->folderId) {
                        $assetsToMove[] = $asset;
                    }
                }
            } else {
                $assetIds = [];

                foreach ($value as $elementFile) {
                    $assetIds[] = $elementFile->id;
                }

                // Find the files with temp sources and just move those.
                $criteria = [
                    'id' => array_merge(['in'], $assetIds),
                    'volumeId' => ':empty:'
                ];

                $assetsToMove = Asset::find()->configure($criteria)->all();

                // If we have some files to move, make sure the folder exists.
                if (!empty($assetsToMove)) {
                    $targetFolder = $this->_resolveVolumePathToFolder(
                        $this->defaultUploadLocationSource,
                        $this->defaultUploadLocationSubpath,
                        $element
                    );
                }
            }

            if (!empty($assetsToMove) && !empty($targetFolder)) {

                // Resolve all conflicts by keeping both
                foreach ($assetsToMove as $asset) {
                    $conflictingAsset = Craft::$app->getAssets()->findAsset([
                        'filename' => $asset->filename,
                        'folderId' => $targetFolder->id
                    ]);

                    if ($conflictingAsset) {
                        $newFilename = Craft::$app->getAssets()->getNameReplacementInFolder($asset->filename,
                            $targetFolder);
                        Craft::$app->getAssets()->moveAsset($asset,
                            $targetFolder->id, $newFilename);
                    } else {
                        Craft::$app->getAssets()->moveAsset($asset,
                            $targetFolder->id);
                    }
                }
            }
        }

        parent::afterElementSave($element);
    }

    /**
     * @inheritdoc
     */
    public function validateValue($value, $element)
    {
        $errors = parent::validateValue($value, $element);

        // Check if this field restricts files and if files are passed at all.
        if (isset($this->restrictFiles) && !empty($this->restrictFiles) && !empty($this->allowedKinds) && is_array($value) && !empty($value)) {
            $allowedExtensions = $this->_getAllowedExtensions($this->allowedKinds);

            foreach ($value as $fileId) {
                $file = Craft::$app->getAssets()->getAssetById($fileId);

                if ($file && !in_array(mb_strtolower(Io::getExtension($file->filename)), $allowedExtensions)) {
                    $errors[] = Craft::t('app', '"{filename}" is not allowed in this field.', ['filename' => $file->filename]);
                }
            }
        }

        foreach ($this->_failedFiles as $file) {
            $errors[] = Craft::t('app', '"{filename}" is not allowed in this field.', ['filename' => $file]);
        }

        return $errors;
    }

    /**
     * @inheritdoc
     */
    public function prepareValue($value, $element)
    {
        // If data strings are passed along, make sure the array keys are retained.
        if (isset($value['data']) && !empty($value['data'])) {
            $class = static::elementType();
            /** @var ElementQuery $query */
            $query = $class::find()
                ->locale($this->getTargetLocale($element));

            // $value might be an array of element IDs
            if (is_array($value)) {
                $query
                    ->id(array_filter($value))
                    ->fixedOrder();

                if ($this->allowLimit && $this->limit) {
                    $query->limit($this->limit);
                } else {
                    $query->limit(null);
                }

                return $query;
            }
        }

        return parent::prepareValue($value,
            $element);
    }


    /**
     * Resolve source path for uploading for this field.
     *
     * @param ElementInterface|null $element
     *
     * @return mixed
     */
    public function resolveDynamicPathToFolderId($element)
    {
        return $this->_determineUploadFolderId($element);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function getInputSources($element)
    {
        // Look for the single folder setting
        if ($this->useSingleFolder) {
            $folderId = $this->_determineUploadFolderId($element);
            Craft::$app->getSession()->authorize('uploadToVolume:'.$folderId);
            $folderPath = 'folder:'.$folderId.':single';

            return [$folderPath];
        }

        $sources = [];

        // If it's a list of source IDs, we need to convert them to their folder counterparts
        if (is_array($this->sources)) {
            foreach ($this->sources as $source) {
                if (strncmp($source, 'folder:', 7) === 0) {
                    $sources[] = $source;
                }
            }
        } else {
            if ($this->sources == '*') {
                $sources = '*';
            }
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    protected function getInputSelectionCriteria()
    {
        $allowedKinds = [];

        if (isset($this->restrictFiles) && !empty($this->restrictFiles) && !empty($this->allowedKinds)) {
            $allowedKinds = $this->allowedKinds;
        }

        return ['kind' => $allowedKinds];
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolve a source path to it's folder ID by the source path and the matched source beginning.
     *
     * @param integer          $volumeId
     * @param string           $subpath
     * @param ElementInterface $element
     *
     * @throws VolumeObjectNotFoundException if the volume doesn’t exist
     * @throws InvalidSubpathException if the subpath cannot be parsed in full
     * @return VolumeFolder
     */
    private function _resolveVolumePathToFolder($volumeId, $subpath, $element)
    {
        // Are we looking for a subfolder?
        $subpath = is_string($subpath) ? trim($subpath, '/') : '';

        if (strlen($subpath) === 0) {
            // Get the root folder in the source
            $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volumeId);

            // Make sure the root folder actually exists
            if (!$folder) {
                throw new VolumeObjectNotFoundException('Cannot find the target folder.');
            }
        } else {
            // Prepare the path by parsing tokens and normalizing slashes.
            try {
                $renderedSubpath = Craft::$app->getView()->renderObjectTemplate($subpath, $element);
            } catch (\Exception $e) {
                throw new InvalidSubpathException($subpath);
            }

            // Did any of the tokens return null?
            if (
                strlen($renderedSubpath) === 0 ||
                trim($renderedSubpath, '/') != $renderedSubpath ||
                strpos($renderedSubpath, '//') !== false
            ) {
                throw new InvalidSubpathException($subpath);
            }

            $subpath = Io::cleanPath($renderedSubpath, Craft::$app->getConfig()->get('convertFilenamesToAscii'));

            $folder = Craft::$app->getAssets()->findFolder([
                'volumeId' => $volumeId,
                'path' => $subpath.'/'
            ]);

            // Ensure that the folder exists
            if (!$folder) {
                // Start at the root, and, go over each folder in the path and create it if it's missing.
                $parentFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volumeId);

                // Make sure the root folder actually exists
                if (!$parentFolder) {
                    throw new VolumeObjectNotFoundException('Cannot find the target folder.');
                }

                $segments = explode('/', $subpath);
                foreach ($segments as $segment) {
                    $folder = Craft::$app->getAssets()->findFolder([
                        'parentId' => $parentFolder->id,
                        'name' => $segment
                    ]);

                    // Create it if it doesn't exist
                    if (!$folder) {
                        $folder = $this->_createSubfolder($parentFolder, $segment);
                    }

                    // In case there's another segment after this...
                    $parentFolder = $folder;
                }
            }
        }

        return $folder;
    }

    /**
     * Create a subfolder within a folder with the given name.
     *
     * @param VolumeFolder $currentFolder
     * @param string       $folderName
     *
     * @return VolumeFolder The new subfolder
     */
    private function _createSubfolder($currentFolder, $folderName)
    {
        $newFolder = new VolumeFolder();
        $newFolder->parentId = $currentFolder->id;
        $newFolder->name = $folderName;
        $newFolder->volumeId = $currentFolder->volumeId;
        $newFolder->path = trim($currentFolder->path.'/'.$folderName, '/').'/';

        try {
            Craft::$app->getAssets()->createFolder($newFolder);
        } catch (AssetConflictException $e) {
            // If folder doesn't exist in DB, but we can't create it, it probably exists on the server.
            Craft::$app->getAssets()->storeFolderRecord($newFolder);
        }

        return $newFolder;
    }

    /**
     * Get a list of allowed extensions for a list of file kinds.
     *
     * @param array $allowedKinds
     *
     * @return array
     */
    private function _getAllowedExtensions($allowedKinds)
    {
        if (!is_array($allowedKinds)) {
            return [];
        }

        $extensions = [];
        $allKinds = Io::getFileKinds();

        foreach ($allowedKinds as $allowedKind) {
            $extensions = array_merge($extensions,
                $allKinds[$allowedKind]['extensions']);
        }

        return $extensions;
    }

    /**
     * Determine an upload folder id by looking at the settings and whether Element this field belongs to is new or not.
     *
     * @param ElementInterface|null $element
     *
     * @return mixed|null
     */
    private function _determineUploadFolderId($element)
    {
        // If there's no dynamic tags in the set path, or if the element has already been saved, we can use the real
        // folder
        if (!empty($element->id)
            || (!empty($this->useSingleFolder) && !StringHelper::contains($this->singleUploadLocationSubpath, '{'))
            || (empty($this->useSingleFolder) && !StringHelper::contains($this->defaultUploadLocationSubpath, '{'))
        ) {
            // Use the appropriate settings for folder determination
            if (empty($this->useSingleFolder)) {
                $folder = $this->_resolveVolumePathToFolder(
                    $this->defaultUploadLocationSource,
                    $this->defaultUploadLocationSubpath,
                    $element
                );
            } else {
                $folder = $this->_resolveVolumePathToFolder(
                    $this->singleUploadLocationSource,
                    $this->singleUploadLocationSubpath,
                    $element
                );
            }
        } else {
            // New element, so we default to User's upload folder for this field
            $userModel = Craft::$app->getUser()->getIdentity();

            $userFolder = Craft::$app->getAssets()->getUserFolder($userModel);

            $folderName = 'field_'.$this->id;
            $elementFolder = Craft::$app->getAssets()->findFolder([
                'parentId' => $userFolder->id,
                'name' => $folderName
            ]);

            if (!$elementFolder) {
                $folder = new VolumeFolder([
                    'parentId' => $userFolder->id,
                    'name' => $folderName,
                    'path' => $userFolder->path.$folderName.'/'
                ]);

                Craft::$app->getAssets()->createFolder($folder);
            } else {
                $folder = $elementFolder;
            }

            Io::ensureFolderExists(Craft::$app->getPath()->getAssetsTempSourcePath().'/'.$folderName);
        }

        return $folder->id;
    }
}
