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
use Sonata\PageBundle\Model\Page;
use Sonata\PageBundle\Model\PageInterface;
use Sonata\PageBundle\Model\PageManagerInterface;
use Sonata\PageBundle\Model\SiteInterface;

/**
 * This class manages PageInterface persistency with the Doctrine ODM.
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class PageManager extends BaseDocumentManager implements PageManagerInterface
{
    /**
     * @var array
     */
    protected $pageDefaults;

    /**
     * @var array
     */
    protected $defaults;

    /**
     * @param string $class
     */
    public function __construct($class, ManagerRegistry $registry, array $defaults = [], array $pageDefaults = [])
    {
        parent::__construct($class, $registry);

        $this->defaults = $defaults;
        $this->pageDefaults = $pageDefaults;
    }

    public function getPageByUrl(SiteInterface $site, $url)
    {
        return $this->findOneBy([
            'url' => $url,
            'site' => $site->getId(),
        ]);
    }

    public function getPager(array $criteria, $page, $limit = 10, array $sort = [])
    {
        $query = $this->getRepository()
            ->createQueryBuilder('p')
            ->select('p');

        $fields = $this->getEntityManager()->getClassMetadata($this->class)->getFieldNames();

        foreach ($sort as $field => $direction) {
            if (!\in_array($field, $fields, true)) {
                throw new \RuntimeException(sprintf("Invalid sort field '%s' in '%s' class", $field, $this->class));
            }
        }
        if (0 === \count($sort)) {
            $sort = ['name' => 'ASC'];
        }
        foreach ($sort as $field => $direction) {
            $query->orderBy(sprintf('p.%s', $field), strtoupper($direction));
        }

        $parameters = [];

        if (isset($criteria['enabled'])) {
            $query->andWhere('p.enabled = :enabled');
            $parameters['enabled'] = $criteria['enabled'];
        }

        if (isset($criteria['edited'])) {
            $query->andWhere('p.edited = :edited');
            $parameters['edited'] = $criteria['edited'];
        }

        if (isset($criteria['site'])) {
            $query->join('p.site', 's');
            $query->andWhere('s.id = :siteId');
            $parameters['siteId'] = $criteria['site'];
        }

        if (isset($criteria['parent'])) {
            $query->join('p.parent', 'pa');
            $query->andWhere('pa.id = :parentId');
            $parameters['parentId'] = $criteria['parent'];
        }

        if (isset($criteria['root'])) {
            $isRoot = (bool) $criteria['root'];
            if ($isRoot) {
                $query->andWhere('p.parent IS NULL');
            } else {
                $query->andWhere('p.parent IS NOT NULL');
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

    public function create(array $defaults = [])
    {
        // create a new page for this routing
        $class = $this->getClass();

        $page = new $class();

        if (isset($defaults['routeName'], $this->pageDefaults[$defaults['routeName']])) {
            $defaults = array_merge($this->pageDefaults[$defaults['routeName']], $defaults);
        } else {
            $defaults = array_merge($this->defaults, $defaults);
        }

        foreach ($defaults as $key => $value) {
            $method = 'set'.ucfirst($key);
            $page->$method($value);
        }

        return $page;
    }

    public function fixUrl(PageInterface $page)
    {
        if ($page->isInternal()) {
            $page->setUrl(null); // internal routes do not have any url ...

            return;
        }

        // hybrid page cannot be altered
        if (!$page->isHybrid()) {
            // make sure Page has a valid url
            if ($page->getParent()) {
                if (!$page->getSlug()) {
                    $page->setSlug(Page::slugify($page->getName()));
                }

                if ('/' === $page->getParent()->getUrl()) {
                    $base = '/';
                } elseif ('/' !== substr($page->getParent()->getUrl(), -1)) {
                    $base = $page->getParent()->getUrl().'/';
                } else {
                    $base = $page->getParent()->getUrl();
                }

                $page->setUrl($base.$page->getSlug());
            } else {
                // a parent page does not have any slug - can have a custom url ...
                $page->setSlug(null);
                $page->setUrl('/'.$page->getSlug());
            }
        }

        foreach ($page->getChildren() as $child) {
            $this->fixUrl($child);
        }
    }

    public function save($page, $andFlush = true)
    {
        if (!$page->isHybrid()) {
            $this->fixUrl($page);
        }

        parent::save($page, $andFlush);

        return $page;
    }

    public function loadPages(SiteInterface $site)
    {
        $allpages = $this->getDocumentManager()
            ->getRepository($this->class)
            ->findBy(['site.$id'=>new ObjectId($site->getId())]);

        if ($allpages) {
            foreach ($allpages as $page){
                $pages[$page->getId()] = $page;
            }
            unset($allpages);
        }

        if ($pages == null){
            $pages = [];
        }

        foreach ($pages as $page) {
            $parent = $page->getParent();

            $page->disableChildrenLazyLoading();
            if (!$parent) {
                continue;
            }

            if (isset($pages[$parent->getId()])){
                $pages[$parent->getId()]->disableChildrenLazyLoading();
                $pages[$parent->getId()]->addChildren($page);
            }

        }

        return $pages;
    }

    /**
     * @return PageInterface[]
     */
    public function getHybridPages(SiteInterface $site)
    {

        $pages =  $this->getDocumentManager()
            ->createQueryBuilder($this->class)
            ->field('routeName')->notEqual(PageInterface::PAGE_ROUTE_CMS_NAME)
            ->field('site.$id')->equals($site->getId())
            ->getQuery()
            ->getSingleResult();

        if ($pages == null) {
            $pages = [];
        }
        return $pages;
    }
}