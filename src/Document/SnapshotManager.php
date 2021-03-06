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

use Doctrine\Persistence\ManagerRegistry;
use MongoDB\BSON\ObjectId;
use Sonata\DatagridBundle\Pager\Doctrine\Pager;
use Sonata\DatagridBundle\ProxyQuery\Doctrine\ProxyQuery;
use Sonata\Doctrine\Document\BaseDocumentManager;
use Sonata\PageBundle\Model\PageInterface;
use Sonata\PageBundle\Model\SnapshotInterface;
use Sonata\PageBundle\Model\SnapshotManagerInterface;
use Sonata\PageBundle\Model\SnapshotPageProxy;
use Sonata\PageBundle\Model\SnapshotPageProxyFactory;
use Sonata\PageBundle\Model\SnapshotPageProxyFactoryInterface;
use Sonata\PageBundle\Model\TransformerInterface;

/**
 * This class manages SnapshotInterface persistency with the Doctrine ODM.
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class SnapshotManager extends BaseDocumentManager implements SnapshotManagerInterface
{
    /**
     * @var array
     */
    protected $children = [];

    /**
     * @var array<string, string>
     */
    protected $templates = [];

    /**
     * @var SnapshotPageProxyFactoryInterface
     */
    protected $snapshotPageProxyFactory;

    /**
     * @param string                            $class                    Namespace of entity class
     * @param ManagerRegistry                   $registry                 An entity manager instance
     * @param array                             $templates                An array of templates
     * @param SnapshotPageProxyFactoryInterface $snapshotPageProxyFactory
     */
    public function __construct($class, ManagerRegistry $registry, $templates = [], SnapshotPageProxyFactoryInterface $snapshotPageProxyFactory = null)
    {
        parent::__construct($class, $registry);

        // NEXT_MAJOR: make $snapshotPageProxyFactory parameter required
        if (null === $snapshotPageProxyFactory) {
            @trigger_error(
                'The $snapshotPageProxyFactory parameter is required with the next major release.',
                E_USER_DEPRECATED
            );
            $snapshotPageProxyFactory = new SnapshotPageProxyFactory(SnapshotPageProxy::class);
        }

        $this->templates = $templates;
        $this->snapshotPageProxyFactory = $snapshotPageProxyFactory;
    }

    public function save($snapshot, $andFlush = true)
    {
        parent::save($snapshot);

        return $snapshot;
    }

    public function enableSnapshots(array $snapshots, \DateTime $date = null)
    {
        if (0 === \count($snapshots)) {
            return;
        }

        $date = $date ?: new \DateTime();
        $pageIds = $snapshotIds = [];

        foreach ($snapshots as $snapshot) {
            $pageIds[] = $snapshot->getPage()->getId();
            $snapshotIds[] = $snapshot->getId();

            $snapshot->setPublicationDateStart($date);
            $snapshot->setPublicationDateEnd(null);

            $this->getDocumentManager()->persist($snapshot);
        }

        $this->getDocumentManager()->flush();
        $this->getDocumentManager()->createQueryBuilder($this->getClass())
            ->findAndUpdate()
            ->field('_id')->notIn($snapshotIds)
            ->field('pageId')->notIn($pageIds)
            ->field('publicationDateEnd')->set(new \MongoDB\BSON\UTCDateTime())
        ->getQuery();
    }

    public function findEnableSnapshot(array $criteria)
    {
        $date = new \DateTime();
        $builder = $this->getDocumentManager()
            ->createQueryBuilder($this->class);

        $builder->addOr(
            $builder->expr()
                ->field('publicationDateEnd')->equals(null)
                ->field('publicationDateEnd')->gte($date),
            $builder->expr()->field('publicationDateStart')->lte($date)
        );

        $query = $builder;

        if (isset($criteria['site'])) {
            $query->field('site.$id')->equals(new ObjectId($criteria['site']));
        }

        if (isset($criteria['pageId'])) {
            $query->field('page.$id')->equals(new ObjectId($criteria['pageId']));
        } elseif (isset($criteria['url'])) {
            $query->field('url')->equals($criteria['url']);
        } elseif (isset($criteria['routeName'])) {
            $query->field('routeName')->equals($criteria['routeName']);
        } elseif (isset($criteria['pageAlias'])) {
            $query->field('pageAlias')->equals($criteria['pageAlias']);
        } elseif (isset($criteria['name'])) {
            $query->field('name')->equals($criteria['name']);
        } else {
            throw new \RuntimeException('please provide a `pageId`, `url`, `routeName` or `name` as criteria key');
        }
        
	$query->sort('createdAt','DESC');
	return $query->getQuery()->getSingleResult();
    }

    /**
     * return a page with the given routeName.
     *
     * @param string $routeName
     *
     * @return PageInterface|false
     *
     * @deprecated since sonata-project/page-bundle 3.2, to be removed in 4.0
     */
    public function getPageByName($routeName)
    {
        @trigger_error(
            'The '.__METHOD__.' method is deprecated since version 3.2 and will be removed in 4.0.',
            E_USER_DEPRECATED
        );

        $snapshots = $this->getDocumentManager()
            ->getRepository($this->class)
            ->findBy(['routeName'=>$routeName]);


        $snapshot = \count($snapshots) > 0 ? $snapshots[0] : false;

        if ($snapshot) {
            return new SnapshotPageProxy($this, $snapshot);
        }

        return false;
    }

    /**
     * @param string[] $templates
     */
    public function setTemplates($templates)
    {
        $this->templates = $templates;
    }

    /**
     * @return string
     */
    public function getTemplates()
    {
        return $this->templates;
    }

    /**
     * @param string $code
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    public function getTemplate($code)
    {
        if (!isset($this->templates[$code])) {
            throw new \RuntimeException(sprintf('No template references with the code : %s', $code));
        }

        return $this->templates[$code];
    }

    public function cleanup(PageInterface $page, $keep)
    {
        if (!is_numeric($keep)) {
            throw new \RuntimeException(sprintf('Please provide an integer value, %s given', \gettype($keep)));
        }

        return  $this->getDocumentManager()
            ->createQueryBuilder($this->class)
            ->remove()
            ->field('page.$id')->equals(new ObjectId($page->getId()))
        ->getQuery()
        ->execute();
    }

    public function getPager(array $criteria, $page, $limit = 10, array $sort = [])
    {
        $query = $this->getRepository()
            ->createQueryBuilder('s')
            ->select('s');

        $parameters = [];

        if (isset($criteria['enabled'])) {
            $query->andWhere('s.enabled = :enabled');
            $parameters['enabled'] = $criteria['enabled'];
        }

        if (isset($criteria['site'])) {
            $query->join('s.site', 'si');
            $query->andWhere('si.id = :siteId');
            $parameters['siteId'] = $criteria['site'];
        }

        if (isset($criteria['page_id'])) {
            $query->join('s.page', 'p');
            $query->andWhere('p.id = :pageId');
            $parameters['pageId'] = $criteria['page_id'];
        }

        if (isset($criteria['parent'])) {
            $query->join('s.parent', 'pa');
            $query->andWhere('pa.id = :parentId');
            $parameters['parentId'] = $criteria['parent'];
        }

        if (isset($criteria['root'])) {
            $isRoot = (bool) $criteria['root'];
            if ($isRoot) {
                $query->andWhere('s.parent IS NULL');
            } else {
                $query->andWhere('s.parent IS NOT NULL');
            }
        }

        $query->setParameters($parameters);

        $pager = new Pager();
        $pager->setMaxPerPage($limit);
        $pager->setQuery(new ProxyQuery($query));
        $pager->setPage($page);
        $pager->init();

        return $pager;
    }

    final public function createSnapshotPageProxy(TransformerInterface $transformer, SnapshotInterface $snapshot)
    {
        return $this->snapshotPageProxyFactory
            ->create($this, $transformer, $snapshot)
        ;
    }
}
