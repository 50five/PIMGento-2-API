<?php
/**
 * Pimgento Api interface for methods.
 *
 * @category  Pimgento
 * @package   Pimgento\Api
 * @author    Sergiy Checkanov <seche@smile.fr>
 * @copyright 2020 Smile
 */
namespace Pimgento\Api\Model\Strategy;

/**
 * Interface GrabVideoInterface.
 */
interface GrabVideoInterface
{
    /**#@+
     * Constants defined for host video.
     */
    const HOST_YOUTUBE = "youtube";
    const HOST_VIMEO = "vimeo";
    /**#@-*/

    /**
     * Get video data after grab.
     *
     * @param string $urlVideo
     * @return array
     */
    public function getVideoData($urlVideo): array;

    /**
     * Get video id from url.
     *
     * @param $url
     * @return mixed|string
     */
    public function getVideoID($url): string;
}
