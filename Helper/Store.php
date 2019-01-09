<?php

namespace Pimgento\Api\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Pimgento\Api\Helper\Config as ConfigHelper;

/**
 * Class Authenticator
 *
 * @category  Class
 * @package   Pimgento\Api\Helper
 * @author    Agence Dn'D <contact@dnd.fr>
 * @copyright 2018 Agence Dn'D
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @link      https://www.pimgento.com/
 */
class Store extends AbstractHelper
{
    /**
     * This variable contains a ConfigHelper
     *
     * @var ConfigHelper $configHelper
     */
    protected $configHelper;
    /**
     * This variable contains a StoreManagerInterface
     *
     * @var StoreManagerInterface $storeManager
     */
    protected $storeManager;
    /**
     * This variable contains a Serializer
     *
     * @var Serializer $serializer
     */
    protected $serializer;

    /**
     * Store constructor
     *
     * @param Context $context
     * @param ConfigHelper $configHelper
     * @param Serializer $serializer
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        ConfigHelper $configHelper,
        Serializer $serializer,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);

        $this->serializer   = $serializer;
        $this->storeManager = $storeManager;
        $this->configHelper = $configHelper;
    }

    /**
     * @param string $arrayKey
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getStores($arrayKey = 'store_id')
    {
        /** @var StoreInterface[] $stores */
        $stores = $this->storeManager->getStores(true);
        /** @var array $data */
        $data = [];

        if (!is_array($arrayKey)) {
            $arrayKey = [$arrayKey];
        }
        /** @var string|array $channels */
        $channels = $this->configHelper->getWebsiteMapping();

        if ($channels) {
            $channels = $this->serializer->unserialize($channels);
            if (!is_array($channels)) {
                $channels = [];
            }
        } else {
            $channels = [];
        }

        /** @var StoreInterface $store */
        foreach ($stores as $store) {
            /** @var WebsiteInterface $website */
            $website = $this->storeManager->getWebsite($store->getWebsiteId());
            /** @var string $channel */
            $channel = $website->getCode();
            /** @var array $match */
            foreach ($channels as $match) {
                if (isset($match['website']) && $match['website'] === $website->getCode()) {
                    $channel = $match['channel'];
                }
            }
            /** @var array $combine */
            $combine = [];
            /** @var string $key */
            foreach ($arrayKey as $key) {
                switch ($key) {
                    case 'store_id':
                        $combine[] = $store->getId();
                        break;
                    case 'store_code':
                        $combine[] = $store->getCode();
                        break;
                    case 'website_id':
                        $combine[] = $website->getId();
                        break;
                    case 'website_code':
                        $combine[] = $website->getCode();
                        break;
                    case 'channel_code':
                        $combine[] = $channel;
                        break;
                    case 'lang':
                        $combine[] = $this->configHelper->getDefaultLocale($store->getId());
                        break;
                    case 'currency':
                        $combine[] = $this->configHelper->getDefaultCurrency($store->getId());
                        break;
                    default:
                        $combine[] = $store->getId();
                        break;
                }
            }
            /** @var string $key */
            $key = join('-', $combine);

            if (!isset($data[$key])) {
                $data[$key] = [];
            }

            $data[$key][] = [
                'store_id'     => $store->getId(),
                'store_code'   => $store->getCode(),
                'website_id'   => $website->getId(),
                'website_code' => $website->getCode(),
                'channel_code' => $channel,
                'lang'         => $this->configHelper->getDefaultLocale($store->getId()),
                'currency'     => $this->configHelper->getDefaultCurrency($store->getId()),
                'root_category_id' => $store->getRootCategoryId(),
            ];
        }

        return $data;
    }

    /**
     * Retrieve all store combination
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getAllStores()
    {
        /** @var array $stores */
        $stores = array_merge(
            $this->getStores(['lang']), // en_US
            $this->getStores(['lang', 'channel_code']), // en_US-channel
            $this->getStores(['channel_code']), // channel
            $this->getStores(['currency']), // USD
            $this->getStores(['channel_code', 'currency']), // channel-USD
            $this->getStores(['lang', 'channel_code', 'currency']) // en_US-channel-USD
        );

        return $stores;
    }
}
