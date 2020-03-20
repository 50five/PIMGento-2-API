<?php
/**
 * Pimgento Api youtube method model.
 *
 * @category  Pimgento
 * @package   Pimgento\Api
 * @author    Sergiy Checkanov <seche@smile.fr>
 * @copyright 2020 Smile
 */

namespace Pimgento\Api\Model\Strategy\Grab;

use Pimgento\Api\Model\Strategy\GrabVideoInterface;
use Magento\ProductVideo\Helper\Media;
use Magento\ProductVideo\Model\Product\Attribute\Media\ExternalVideoEntryConverter;

/**
 * Interface GrabVideoInterface.
 */
class Youtube implements GrabVideoInterface
{
    /**#@+
     * Constant defined for API youtube.
     */
    const API_YOUTUBE_URL = "https://www.googleapis.com/youtube/v3/videos?part=snippet%2CcontentDetails%2Cstatistics";
    /**#@-*/

    /**
     * Media product video helper.
     *
     * @var Media
     */
    protected $mediaHelper;

    /**
     * Youtube constructor.
     *
     * @param Media $mediaHelper
     */
    public function __construct(
        Media $mediaHelper
    ) {
        $this->mediaHelper = $mediaHelper;
    }

    /**
     * Get video data after grab.
     *
     * @param string $urlVideo
     * @return array
     */
    public function getVideoData($urlVideo): array
    {
        $api_url = self::API_YOUTUBE_URL . '&id=' . $this->getVideoID($urlVideo) . '&key=' . $this->getYouTubeApiKey();

        $data = json_decode(file_get_contents($api_url));
        if (count($data->items) === 0) {
            return [];
        }

        $snippet = $data->items[0]->snippet;

        $videoData = [
            'video_id' => $this->getVideoID($urlVideo),
            'video_title' => $snippet->title,
            'video_description' => $snippet->description,
            'thumbnail' => $snippet->thumbnails->maxres->url,
            'video_provider' => GrabVideoInterface::HOST_YOUTUBE,
            'video_metadata' => null,
            'video_url' => $urlVideo,
            'media_type' => ExternalVideoEntryConverter::MEDIA_TYPE_CODE,
        ];

        return $videoData;
    }

    /**
     * Get video id from url.
     *
     * @param $url
     * @return mixed|string
     */
    public function getVideoID($url): string {
        $queryString = parse_url($url, PHP_URL_QUERY);

        parse_str($queryString, $params);
        if (isset($params['v']) && strlen($params['v']) > 0) {
            return $params['v'];
        } else {
            return "";
        }
    }

    /**
     * Get youtube api key from configuration.
     *
     * @return string
     */
    protected function getYouTubeApiKey(): string
    {
        return $this->mediaHelper->getYouTubeApiKey();
    }
}
