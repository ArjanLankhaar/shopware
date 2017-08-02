<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Bundle\StoreFrontBundle\Media;

use Doctrine\DBAL\Connection;
use Shopware\Framework\Struct\FieldHelper;
use Shopware\Context\TranslationContext;

/**
 * @category  Shopware
 *
 * @copyright Copyright (c) shopware AG (http://www.shopware.de)
 */
class MediaGateway
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var \Shopware\Framework\Struct\FieldHelper
     */
    private $fieldHelper;

    /**
     * @var MediaHydrator
     */
    private $hydrator;

    /**
     * @param Connection    $connection
     * @param \Shopware\Framework\Struct\FieldHelper   $fieldHelper
     * @param MediaHydrator $hydrator
     */
    public function __construct(
        Connection $connection,
        FieldHelper $fieldHelper,
        MediaHydrator $hydrator
    ) {
        $this->connection = $connection;
        $this->fieldHelper = $fieldHelper;
        $this->hydrator = $hydrator;
    }

    /**
     * @param int[]              $ids
     * @param TranslationContext $context
     *
     * @return \Shopware\Bundle\StoreFrontBundle\Media\Media[] Indexed by the media id
     */
    public function getList($ids, TranslationContext $context)
    {
        $query = $this->getQuery($context);

        $query->setParameter(':ids', $ids, Connection::PARAM_INT_ARRAY);

        /** @var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $query->execute();

        $data = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($data as $row) {
            $mediaId = $row['__media_id'];
            $result[$mediaId] = $this->hydrator->hydrate($row);
        }

        return $result;
    }

    /**
     * @param TranslationContext $context
     *
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    private function getQuery(TranslationContext $context)
    {
        $query = $this->connection->createQueryBuilder();

        $query->select($this->fieldHelper->getMediaFields());

        $query->from('s_media', 'media')
            ->innerJoin('media', 's_media_album_settings', 'mediaSettings', 'mediaSettings.albumID = media.albumID')
            ->leftJoin('media', 's_media_attributes', 'mediaAttribute', 'mediaAttribute.mediaID = media.id')
            ->where('media.id IN (:ids)');

        $this->fieldHelper->addMediaTranslation($query, $context);

        return $query;
    }
}