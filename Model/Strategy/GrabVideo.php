<?php
/**
 * Pimgento Api Strategy for parce right method.
 *
 * @category  Pimgento
 * @package   Pimgento\Api
 * @author    Sergiy Checkanov <seche@smile.fr>
 * @copyright 2020 Smile
 */
namespace Pimgento\Api\Model\Strategy;

use Magento\Framework\Model\AbstractModel;
use Magento\ProductVideo\Helper\Media;
use Pimgento\Api\Model\Strategy\Grab\Youtube;
use Pimgento\Api\Model\Strategy\Grab\Vimeo;

/**
 * Class GrabVideo.
 *
 * @package Pimgento\Api\Model\Strategy
 */
class GrabVideo
{
    /**
     * Youtube model.
     *
     * @var Youtube
     */
    protected $youtubeModel;
    
    /**
     * Vimeo model.
     *
     * @var Vimeo
     */
    protected $vimeoModel;

    /**
     * GrabVideo constructor.
     *
     * @param Youtube $youtubeModel
     * @param Vimeo $vimeoModel
     */
    public function __construct(
        Youtube $youtubeModel,
        Vimeo $vimeoModel
    ) {
        $this->youtubeModel = $youtubeModel;
        $this->vimeoModel = $vimeoModel;
    }

    /**
     * Strategy for getting right class.
     *
     * @param string $videoUrl
     * @return array
     * @throws \Exception
     */
    public function getGrabMethod($videoUrl)
    {
        $host = parse_url($videoUrl, PHP_URL_HOST);

        if (strstr($host, GrabVideoInterface::HOST_YOUTUBE)) {
            return $this->youtubeModel->getVideoData($videoUrl);
        } else if (strstr($host, GrabVideoInterface::HOST_VIMEO)) {
            return $this->vimeoModel->getVideoData($videoUrl);
        } else {
            throw new \Exception("Unknown Grab Method");
        }
    }
}
