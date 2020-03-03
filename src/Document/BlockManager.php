<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\Document;

use Sonata\BlockBundle\Model\BlockInterface;
use Sonata\BlockBundle\Model\BlockManagerInterface;
use Sonata\DatagridBundle\Pager\Doctrine\Pager;
use Sonata\DatagridBundle\ProxyQuery\Doctrine\ProxyQuery;
use Sonata\Doctrine\Document\BaseDocumentManager;

/**
 * This class manages BlockInterface persistency with the Doctrine ODM.
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class BlockManager extends BaseDocumentManager implements BlockManagerInterface
{
    public function save($block, $andFlush = true)
    {
        parent::save($block, $andFlush);

        return $block;
    }

    /**
     * Updates position for given block.
     *
     * @param int  $id       Block Id
     * @param int  $position New Position
     * @param int  $parentId Parent block Id (needed when partial = true)
     * @param int  $pageId   Page Id (needed when partial = true)
     * @param bool $partial  Should we use partial references? (Better for performance, but can lead to query issues.)
     *
     * @return BlockInterface
     */
    public function updatePosition($id, $position, $parentId = null, $pageId = null, $partial = true)
    {
        if ($partial) {
            $meta = $this->getEntityManager()->getClassMetadata($this->getClass());

            // retrieve object references
            $block = $this->getDocumentManager()->getReference($this->getClass(), $id);
            $pageRelation = $meta->getAssociationMapping('page');
            $page = $this->getDocumentManager()->getPartialReference($pageRelation['targetEntity'], $pageId);

            $parentRelation = $meta->getAssociationMapping('parent');
            $parent = $this->getDocumentManager()->getPartialReference($parentRelation['targetEntity'], $parentId);

            $block->setPage($page);
            $block->setParent($parent);
        } else {
            $block = $this->find($id);
        }

        // set new values
        $block->setPosition($position);
        $this->getDocumentManager()->persist($block);

        return $block;
    }

    public function getPager(array $criteria, $page, $limit = 10, array $sort = [])
    {
        $query = $this->getRepository()
            ->createQueryBuilder('b')
            ->select('b');

        $parameters = [];

        if (isset($criteria['enabled'])) {
            $query->andWhere('p.enabled = :enabled');
            $parameters['enabled'] = $criteria['enabled'];
        }

        if (isset($criteria['type'])) {
            $query->andWhere('p.type = :type');
            $parameters['type'] = $criteria['type'];
        }

        $query->setParameters($parameters);

        $pager = new Pager();
        $pager->setMaxPerPage($limit);
        $pager->setQuery(new ProxyQuery($query));
        $pager->setPage($page);
        $pager->init();

        return $pager;
    }
}
