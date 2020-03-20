<?php
/**
 * Pimgento Api Process for get information and save.
 *
 * @category  Pimgento
 * @package   Pimgento\Api
 * @author    Sergiy Checkanov <seche@smile.fr>
 * @copyright 2020 Smile
 */

namespace Pimgento\Api\Helper;

use Exception;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\Processor;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product\Gallery\CreateHandler;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\File\Uploader;
use Magento\Framework\Filesystem\Directory\Read;
use Magento\Framework\HTTP\Adapter\Curl;
use Magento\Framework\Image\AdapterFactory;
use Magento\Framework\Validator\ValidatorInterface;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Catalog\Model\Product\Media\Config;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\Validator\NotProtectedExtension;
use Magento\MediaStorage\Model\ResourceModel\File\Storage\File;
use Magento\ProductVideo\Controller\Adminhtml\Product\Gallery\RetrieveImage;
use Magento\Staging\Model\VersionManager;
use Pimgento\Api\Model\Strategy\GrabVideo;
use Pimgento\Api\Helper\Config as ConfigHelper;

/**
 * Class VideoProcessor
 *
 * @package Pimgento\Api\Helper
 */
class VideoProcessor extends Processor
{
    /**
     * Handler for proccess video save.
     *
     * @var CreateHandler
     */
    protected $createHandler;

    /**
     * Strategy model for get right data.
     *
     * @var GrabVideo
     */
    protected $strategyGrabVideo;

    /**
     * File system model.
     *
     * @var Filesystem
     */
    protected $fileSystem;

    /**
     * Image adapter.
     *
     * @var AdapterFactory
     */
    protected $imageAdapter;

    /**
     * Curl.
     *
     * @var Curl
     */
    protected $curl;

    /**
     * File model.
     *
     * @var File
     */
    protected $fileUtility;

    /**
     * Extension validator.
     *
     * @var NotProtectedExtension
     */
    protected $extensionValidator;

    /**
     * Protocol validator.
     *
     * @var ValidatorInterface
     */
    protected $protocolValidator;

    /**
     * This variable contains a ConfigHelper
     *
     * @var ConfigHelper $configHelper
     */
    protected $configHelper;

    /**
     * VideoProcessor constructor.
     *
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param Database $fileStorageDb
     * @param Config $mediaConfig
     * @param Filesystem $filesystem
     * @param Gallery $resourceModel
     * @param \Pimgento\Api\Helper\Config $configHelper
     * @param CreateHandler $createHandler
     * @param GrabVideo $strategyGrabVideo
     * @param Filesystem $fileSystem
     * @param AdapterFactory $imageAdapterFactory
     * @param Curl $curl
     * @param File $fileUtility
     * @param ValidatorInterface|null $protocolValidator
     * @param NotProtectedExtension|null $extensionValidator
     */
    public function __construct(
        ProductAttributeRepositoryInterface $attributeRepository,
        Database $fileStorageDb,
        Config $mediaConfig,
        Filesystem $filesystem,
        Gallery $resourceModel,
        ConfigHelper $configHelper,
        CreateHandler $createHandler,
        GrabVideo $strategyGrabVideo,
        Filesystem $fileSystem,
        AdapterFactory $imageAdapterFactory,
        Curl $curl,
        File $fileUtility,
        ValidatorInterface $protocolValidator = null,
        NotProtectedExtension $extensionValidator = null
    ) {
        parent::__construct(
            $attributeRepository,
            $fileStorageDb,
            $mediaConfig,
            $filesystem,
            $resourceModel
        );
        $this->createHandler = $createHandler;
        $this->strategyGrabVideo = $strategyGrabVideo;
        $this->fileSystem = $fileSystem;
        $this->configHelper = $configHelper;
        $this->imageAdapter = $imageAdapterFactory->create();
        $this->curl = $curl;
        $this->fileUtility = $fileUtility;
        $this->extensionValidator = $extensionValidator
            ?: ObjectManager::getInstance()
                ->get(NotProtectedExtension::class);
        $this->protocolValidator = $protocolValidator ?:
            ObjectManager::getInstance()
                ->get(ValidatorInterface::class);
    }

    /**
     * Add video data.
     *
     * @param Product $product
     * @param string $videoUrl
     * @param $store_ids
     * @param array $mediaAttribute
     * @param bool $move
     * @param bool $exclude
     * @return string
     * @throws Exception
     */
    public function addVideo(
        Product $product,
        string $videoUrl,
        $store_ids,
        $mediaAttribute = null,
        $move = false,
        $exclude = false
    ) {
        $videoData = $this->getVideoInformation($videoUrl);

        if (!$product->hasGalleryAttribute() || !isset($videoData['video_id'])) {
            return false;
        }

        $product->setStoreId(0);

        $attrCode = $this->getAttribute()->getAttributeCode();
        $mediaGalleryData = $product->getData($attrCode);

        $baseTmpMediaPath = $this->mediaConfig->getBaseTmpMediaPath();
        try {
            $remoteFileUrl = $videoData['thumbnail'];
            $this->validateRemoteFile($remoteFileUrl);
            $localFileName = $videoData['video_id'] . Uploader::getCorrectFileName(basename($remoteFileUrl));
            $localTmpFileName = Uploader::getDispersionPath($localFileName) . DIRECTORY_SEPARATOR . $localFileName;
            $localFilePath = $baseTmpMediaPath . ($localTmpFileName);
            $localUniqFilePath = $this->appendNewFileName($localFilePath);
            $this->validateRemoteFileExtensions($localUniqFilePath);
            $this->retrieveRemoteImage($remoteFileUrl, $localUniqFilePath);
            $localFileFullPath = $this->appendAbsoluteFileSystemPath($localUniqFilePath);
            $this->imageAdapter->validateUploadFile($localFileFullPath);
            $resultUploadFile = $this->appendResultSaveRemoteImage($localUniqFilePath);
        } catch (Exception $e) {
            $fileWriter = $this->fileSystem->getDirectoryWrite(DirectoryList::MEDIA);
            if (isset($localFileFullPath) && $fileWriter->isExist($localFileFullPath)) {
                $fileWriter->delete($localFileFullPath);
            }
        }
        $fileName = $resultUploadFile['file'];

        $fileName = str_replace('\\', '/', $fileName);

        if (!is_array($mediaGalleryData)) {
            $mediaGalleryData = ['images' => []];
        }

        unset($videoData['file']);
        $mediaGalleryData['images'][] = array_merge([
            'file' => $fileName,
            'label' => $videoData['video_title'],
            'disabled' => (int) true
        ], $videoData);

        /**
         * Set data about video in global store.
         */
        $product->setData($attrCode, $mediaGalleryData);

        $this->createHandler->execute($product,
            [
                'row_id' => (int) $product->getId(),
                'created_in' => (int) true,
                'updated_in' => VersionManager::MAX_VERSION
            ]
        );

        /**
         * after creation video on global store need to show video on specific stores
         */
        $mediaGalleryData = $product->getData($attrCode);

        foreach ($mediaGalleryData['images'] as &$mediaAttribute) {
            if ($mediaAttribute['media_type'] == "external-video"
                && $mediaAttribute['video_url'] == $videoData['video_url']
            ) {
                $this->insertGalleryValue(
                    [
                        'value_id' => $mediaAttribute['value_id'],
                        'row_id' => $product->getId(),
                        'disabled' => (int) false,
                        'store_ids' => $store_ids
                    ]
                );
                if ($this->configHelper->isEnabledSortVideo()) {
                    $mediaAttribute['position'] = 0;
                }
            }
        }

        /**
         * Need to save product for create correct link in entity.
         */
        $product->setMediaAttribute($product, $mediaGalleryData, $fileName);
        $product->save();
    }

    /**
     * Change properties after creation video.
     *
     * @param $data
     */
    protected function insertGalleryValue($data)
    {
        $stores = $data['store_ids'];
        unset($data['store_ids']);

        /**for right store show video**/
        foreach ($stores as $store) {
            $data['store_id'] = $store;
            $data['disabled'] = false;
            $this->resourceModel->insertGalleryValueInStore($data);
        }
    }

    /**
     * Get video imformation.
     *
     * @param $videoUrl
     * @return array
     * @throws Exception
     */
    protected function getVideoInformation($videoUrl)
    {
        return $this->strategyGrabVideo->getGrabMethod($videoUrl);
    }

    /**
     * Validate remote file
     *
     * @param string $remoteFileUrl
     * @throws LocalizedException
     *
     * @return $this
     */
    private function validateRemoteFile($remoteFileUrl)
    {
        if (!$this->protocolValidator->isValid($remoteFileUrl)) {
            throw new LocalizedException(
                __("Protocol isn't allowed")
            );
        }

        return $this;
    }

    /**
     * Invalidates files that have script extensions.
     *
     * @param string $filePath
     * @throws ValidatorException
     * @return void
     */
    private function validateRemoteFileExtensions($filePath)
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        if (!$this->extensionValidator->isValid($extension)) {
            throw new ValidatorException(__('Disallowed file type.'));
        }
    }

    /**
     * Append information about remote image.
     *
     * @param string $fileName
     * @return mixed
     */
    protected function appendResultSaveRemoteImage($fileName)
    {
        $fileInfo = pathinfo($fileName);
        $tmpFileName = Uploader::getDispersionPath($fileInfo['basename']) . DIRECTORY_SEPARATOR . $fileInfo['basename'];
        $result['name'] = $fileInfo['basename'];
        $result['type'] = $this->imageAdapter->getMimeType();
        $result['error'] = 0;
        $result['size'] = filesize($this->appendAbsoluteFileSystemPath($fileName));
        $result['url'] = $this->mediaConfig->getTmpMediaUrl($tmpFileName);
        $result['file'] = $tmpFileName;

        return $result;
    }

    /**
     * Trying to get remote image to save it locally
     *
     * @param string $fileUrl
     * @param string $localFilePath
     * @return void
     * @throws LocalizedException
     */
    protected function retrieveRemoteImage($fileUrl, $localFilePath)
    {
        $this->curl->setConfig(['header' => false]);
        $this->curl->write('GET', $fileUrl);
        $image = $this->curl->read();

        if (empty($image)) {
            throw new LocalizedException(
                __('The preview image information is unavailable. Check your connection and try again.')
            );
        }

        $this->fileUtility->saveFile($localFilePath, $image);
    }

    /**
     * Append new file name.
     *
     * @param string $localFilePath
     * @return string
     */
    protected function appendNewFileName($localFilePath): string
    {
        $destinationFile = $this->appendAbsoluteFileSystemPath($localFilePath);
        $fileName = Uploader::getNewFileName($destinationFile);
        $fileInfo = pathinfo($localFilePath);

        return $fileInfo['dirname'] . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * Append absolute file path.
     *
     * @param string $localTmpFile
     * @return string
     */
    protected function appendAbsoluteFileSystemPath($localTmpFile): string
    {
        /** @var Read $mediaDirectory */
        $mediaDirectory = $this->fileSystem->getDirectoryRead(DirectoryList::MEDIA);
        $pathToSave = $mediaDirectory->getAbsolutePath();

        return $pathToSave . $localTmpFile;
    }
}
