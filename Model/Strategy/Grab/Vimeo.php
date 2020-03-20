<?php
/**
 * Pimgento Api vimeo method model.
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
class Vimeo implements GrabVideoInterface
{
    /**#@+
     * Constant defined for API youtube.
     */
    const API_VIMEO_URL = "https://www.vimeo.com/api/v2/video/";
    /**#@-*/

    /**
     * Get video data after grab.
     *
     * @param string $urlVideo
     * @return array
     */
    public function getVideoData($urlVideo): array
    {
        $api_url = self::API_VIMEO_URL . $this->getVideoID($urlVideo) . '.json';

        $data = json_decode(file_get_contents($api_url));
        if (count($data) === 0) {
            return [];
        }

        $snippet = $data[0];

        $videoData = [
            'video_id' => $this->getVideoID($urlVideo),
            'video_title' => $snippet->title,
            'video_description' => $snippet->description,
            'thumbnail' => $snippet->thumbnail_medium,
            'video_provider' => GrabVideoInterface::HOST_VIMEO,
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
        return substr(parse_url($url, PHP_URL_PATH), 1);
    }
}
